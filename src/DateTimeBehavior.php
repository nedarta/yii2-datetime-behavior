<?php
namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;

class DateTimeBehavior extends Behavior
{
	/**
	 * @var array attributes to handle
	 */
	public array $attributes = [];

	/**
	 * @var string input format from UI (for parsing and formatting)
	 */
	public string $inputFormat = 'Y-m-d H:i';

	/**
	 * @var string timezone where user inputs datetime
	 */
	public string $displayTimeZone = 'Europe/Riga';

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
		];
	}

	/**
	 * Convert unix timestamp (UTC) → formatted string (display timezone)
	 * This runs when loading from database
	 */
	public function afterFind(): void
	{
		foreach ($this->attributes as $attribute) {
			$value = $this->owner->{$attribute};

			if ($value === null || $value === '' || $value === 0) {
				continue;
			}

			// Only convert if it's an integer (unix timestamp)
			if (!is_int($value)) {
				continue;
			}

			// Create DateTime from timestamp (already in UTC)
			$dt = new \DateTime();
			$dt->setTimestamp($value);
			// Now convert to display timezone
			$dt->setTimezone(new \DateTimeZone($this->displayTimeZone));

			$this->owner->{$attribute} = $dt->format($this->inputFormat);
		}
	}

	/**
	 * Convert UI string → unix timestamp (UTC)
	 * This runs before validation
	 */
	public function beforeValidate(): void
	{
		foreach ($this->attributes as $attribute) {
			$value = $this->owner->{$attribute};

			// Store original value for potential restoration
			$this->_originalValues[$attribute] = $value;

			if ($value === null || $value === '') {
				continue;
			}

			// If already unix timestamp, skip
			if (is_int($value)) {
				continue;
			}

			$dt = \DateTime::createFromFormat(
				$this->inputFormat,
				$value,
				new \DateTimeZone($this->displayTimeZone)
			);

			if ($dt === false) {
				// Invalid format - keep original value for validation error display
				continue;
			}

			// Convert to UTC and get timestamp
			$dt->setTimezone(new \DateTimeZone($this->serverTimeZone));
			$this->owner->{$attribute} = $dt->getTimestamp();
		}
	}

	/**
	 * Convert back to formatted string after validation
	 * So form displays correctly on validation errors
	 */
	public function afterValidate(): void
	{
		// Only convert back if there were validation errors
		if ($this->owner->hasErrors()) {
			foreach ($this->attributes as $attribute) {
				$value = $this->owner->{$attribute};

				// If attribute has specific errors, restore original value
				if ($this->owner->hasErrors($attribute)) {
					$this->owner->{$attribute} = $this->_originalValues[$attribute] ?? $value;
					continue;
				}

				if ($value === null || $value === '' || !is_int($value)) {
					continue;
				}

				// Create DateTime from timestamp
				$dt = new \DateTime();
				$dt->setTimestamp($value);
				// Convert to display timezone
				$dt->setTimezone(new \DateTimeZone($this->displayTimeZone));

				$this->owner->{$attribute} = $dt->format($this->inputFormat);
			}
		}

		// Clear temporary storage
		$this->_originalValues = [];
	}
}