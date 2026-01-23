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

	public function events(): array
	{
		return [
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
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

			// If it matches db format, parsed as original, skip?
			// But here we rely on the attribute being dirty and possibly in input format.
			// Ideally we should check if $value is already in DB format?
			// But for 'unix', checking if it is int is easy. For 'datetime', it's harder to distinguish from input format.
			// We'll process it if we can parse it from inputFormat.

			$dt = \DateTime::createFromFormat(
				$this->inputFormat,
				$value,
				$this->getDisplayTimeZone()
			);

			if ($dt === false) {
				// Could not parse as input format.
				// Assume it might already be in DB format or invalid.
				// We do not touch it.
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

	protected function getDisplayTimeZone(): \DateTimeZone
	{
		$tz = $this->displayTimeZone;
		if ($tz instanceof \Closure) {
			$tz = call_user_func($tz);
		}
		return new \DateTimeZone($tz);
	}

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

	protected function isEmpty(mixed $value): bool
	{
		return $value === null || $value === '' || $value === 0;
	}
}