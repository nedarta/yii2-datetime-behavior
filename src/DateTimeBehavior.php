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
	 * @var string unix|datetime
	 */
	public string $dbFormat = 'unix';

	/**
	 * @var string input format from UI (for parsing and formatting)
	 */
	public string $inputFormat = 'Y-m-d H:i';

	/**
	 * @var string|\Closure timezone where user inputs datetime
	 */
	public string|\Closure $displayTimeZone = 'UTC';

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

			$this->owner->{$attribute} = $dt->format($this->inputFormat);
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

			$dt = \DateTime::createFromFormat(
				$this->inputFormat,
				$value,
				$this->getDisplayTimeZone()
			);

			if ($dt === false) {
				// Could not parse as input format - leave as is for validation to catch
				continue;
			}

			// Convert to Server Timezone (UTC)
			$dt->setTimezone(new \DateTimeZone($this->serverTimeZone));

			// Store in DB format
			if ($this->dbFormat === 'datetime') {
				$this->owner->{$attribute} = $dt->format('Y-m-d H:i:s');
			} else {
				$this->owner->{$attribute} = $dt->getTimestamp();
			}
		}
	}

	/**
	 * Check if value is already in database format
	 */
	protected function isDbFormat(mixed $value): bool
	{
		if ($this->dbFormat === 'unix') {
			return is_int($value);
		}

		// For datetime format, check if it matches Y-m-d H:i:s pattern
		if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
			return true;
		}

		return false;
	}

	/**
	 * Get display timezone (resolve closure if needed)
	 */
	protected function getDisplayTimeZone(): \DateTimeZone
	{
		$tz = $this->displayTimeZone;
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

		// DateTime format
		return \DateTime::createFromFormat('Y-m-d H:i:s', $value, $tz);
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