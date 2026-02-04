<?php
namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;

/**
 * DateTime Behavior for converting between unix timestamps and formatted dates
 *
 * @property ActiveRecord $owner
 */
class DateTimeBehavior extends Behavior
{
	/**
	 * @var array attributes to handle
	 */
	public array $attributes = [];

	/**
	 * @var string unix|datetime|date|time or any custom PHP date format string (e.g. 'Ymd')
	 */
	public string $dbFormat = 'unix';

	/**
	 * @var string input format from UI (for parsing and formatting)
	 */
	public string $inputFormat = 'Y-m-d H:i';

	/**
	 * @var string|\Closure|null timezone where user inputs datetime. If null, uses Yii::$app->formatter->timeZone or Yii::$app->timeZone.
	 */
	public string|\Closure|null $displayTimeZone = null;

	/**
	 * @var string database timezone (always UTC)
	 */
	public string $serverTimeZone = 'UTC';

	/**
	 * @var array temporary storage for original values during validation
	 */
	private array $_originalValues = [];

	public function events(): array
	{
		return [
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
			ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
			ActiveRecord::EVENT_AFTER_VALIDATE => 'afterValidate',
			ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
			ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
		];
	}

	/**
	 * Convert DB value (UTC) → formatted string (display timezone)
	 * This runs when loading from database
	 */
	public function afterFind(): void
	{
		foreach ($this->attributes as $attribute) {
			$value = $this->owner->{$attribute};

			if ($this->isEmpty($value)) {
				continue;
			}

			// Create DateTime from DB value
			$dt = $this->createDateTimeFromDb($value);

			if ($dt === false) {
				continue;
			}

			// Convert to display timezone
			$dt->setTimezone($this->getDisplayTimeZone());

			// Append offset so Yii formatter knows it's already localized
			$this->owner->{$attribute} = $dt->format($this->inputFormat . ' P');
		}
	}

	/**
	 * Store original values before validation
	 */
	public function beforeValidate(): void
	{
		foreach ($this->attributes as $attribute) {
			$this->_originalValues[$attribute] = $this->owner->{$attribute};
		}
	}

	/**
	 * Restore original values if validation failed
	 */
	public function afterValidate(): void
	{
		if ($this->owner->hasErrors()) {
			foreach ($this->attributes as $attribute) {
				// Restore original value so user sees what they typed
				if ($this->owner->hasErrors($attribute)) {
					$this->owner->{$attribute} = $this->_originalValues[$attribute] ?? null;
				}
			}
		}
		
		// Clear temporary storage
		$this->_originalValues = [];
	}

	/**
	 * Convert UI string → DB value (UTC)
	 * This runs before saving to database
	 */
	public function beforeSave(): void
	{
		foreach ($this->attributes as $attribute) {
			$value = $this->owner->{$attribute};

			if ($this->isEmpty($value)) {
				continue;
			}

			// Skip if already in DB format
			if ($this->isDbFormat($value)) {
				continue;
			}

			$dt = $this->parseInput($value);

			if ($dt === false) {
				// Could not parse as input format - leave as is for validation to catch
				continue;
			}

			$this->owner->{$attribute} = $this->formatForDb($dt);
		}
	}

	/**
	 * Normalize an array of data (UI format → DB format)
	 * Useful for batch operations like updateAll()
	 * 
	 * @param array $data
	 * @return array
	 */
	public function normalize(array $data): array
	{
		foreach ($data as $attribute => $value) {
			if (!in_array($attribute, $this->attributes)) {
				continue;
			}

			if ($this->isEmpty($value) || $this->isDbFormat($value)) {
				continue;
			}

			$dt = $this->parseInput($value);
			if ($dt !== false) {
				$data[$attribute] = $this->formatForDb($dt);
			}
		}

		return $data;
	}

	/**
	 * Helper to format DateTime for DB based on configuration
	 */
	protected function formatForDb(\DateTime $dt): string|int
	{
		// Convert to Server Timezone (UTC)
		$dt->setTimezone(new \DateTimeZone($this->serverTimeZone));

		if ($this->dbFormat === 'unix') {
			return $dt->getTimestamp();
		}

		$format = match ($this->dbFormat) {
			'datetime' => 'Y-m-d H:i:s',
			'date' => 'Y-m-d',
			'time' => 'H:i:s',
			default => $this->dbFormat,
		};

		return $dt->format($format);
	}

	/**
	 * Helper to parse input string using behavior config
	 */
	protected function parseInput(mixed $value): \DateTime|false
	{
		$value = (string)$value;
		
		// Try parsing with timezone offset first (from afterFind)
		$dt = \DateTime::createFromFormat(
			'!' . $this->inputFormat . ' P',
			$value,
			$this->getDisplayTimeZone()
		);

		if ($dt === false) {
			// Try parsing without offset (from UI form)
			$dt = \DateTime::createFromFormat(
				'!' . $this->inputFormat,
				$value,
				$this->getDisplayTimeZone()
			);
		}

		return $dt;
	}

	/**
	 * Get UTC timestamp for an attribute, handling both raw and formatted values
	 */
	public function toTimestamp(string $attribute): ?int
	{
		$value = $this->owner->{$attribute};

		if ($this->isEmpty($value)) {
			return null;
		}

		if (is_numeric($value)) {
			return (int)$value;
		}

		$dt = $this->parseInput($value);
		if ($dt === false) {
			return null;
		}

		// Convert to UTC to get a standard timestamp
		$dt->setTimezone(new \DateTimeZone($this->serverTimeZone));
		return $dt->getTimestamp();
	}

	/**
	 * Check if value is already in database format
	 */
	protected function isDbFormat(mixed $value): bool
	{
		if ($this->dbFormat === 'unix') {
			return is_int($value) || (is_string($value) && ctype_digit($value));
		}

		if (!is_string($value)) {
			return false;
		}

		$format = match ($this->dbFormat) {
			'datetime' => 'Y-m-d H:i:s',
			'date' => 'Y-m-d',
			'time' => 'H:i:s',
			default => $this->dbFormat,
		};

		// Try to parse it. If it parses perfectly, it's likely the DB format.
		$dt = \DateTime::createFromFormat('!' . $format, $value);
		return $dt && $dt->format($format) === $value;
	}

	/**
	 * Get display timezone (resolve closure if needed)
	 */
	protected function getDisplayTimeZone(): \DateTimeZone
	{
		$tz = $this->displayTimeZone;

		if ($tz === null) {
			// Fallback to Yii2 config
			$tz = Yii::$app->formatter->timeZone ?? Yii::$app->timeZone;
		}

		if ($tz instanceof \Closure) {
			$tz = call_user_func($tz);
		}

		return new \DateTimeZone($tz);
	}

	/**
	 * Create DateTime object from database value
	 */
	protected function createDateTimeFromDb(mixed $value): \DateTime|false
	{
		$tz = new \DateTimeZone($this->serverTimeZone);

		if ($this->dbFormat === 'unix') {
			if (!is_numeric($value)) {
				return false;
			}
			$dt = new \DateTime('now', $tz);
			$dt->setTimestamp((int)$value);
			return $dt;
		}

		$format = match ($this->dbFormat) {
			'datetime' => 'Y-m-d H:i:s',
			'date' => 'Y-m-d',
			'time' => 'H:i:s',
			default => $this->dbFormat,
		};

		// Use ! to reset time for formats that don't include it
		return \DateTime::createFromFormat('!' . $format, (string)$value, $tz);
	}

	/**
	 * Check if value is empty
	 */
	protected function isEmpty(mixed $value): bool
	{
		// Don't treat 0 as empty for unix timestamps (it's a valid date: 1970-01-01)
		if ($this->dbFormat === 'unix' && $value === 0) {
			return false;
		}
		
		return $value === null || $value === '';
	}
}