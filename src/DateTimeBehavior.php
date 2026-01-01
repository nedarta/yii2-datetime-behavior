<?php

namespace nedarta\behaviors;

use yii\base\Behavior;
use yii\db\ActiveRecord;

class DateTimeBehavior extends Behavior
{
	public array $attributes = [];

	public string $dbFormat = 'unix'; // unix | datetime
	public string $inputFormat = 'Y-m-d H:i';

	public string $serverTimeZone = 'UTC';
	public string $displayTimeZone = 'UTC';

	public function events(): array
	{
		return [
			ActiveRecord::EVENT_AFTER_FIND  => 'afterFind',
			ActiveRecord::EVENT_BEFORE_SAVE => 'beforeSave',
		];
	}

	public function afterFind(): void
	{
		foreach ($this->attributes as $attribute) {
			if (empty($this->owner->$attribute)) {
				continue;
			}

			$this->owner->$attribute = $this->dbToUi($this->owner->$attribute);
		}
	}

	public function beforeSave(): void
	{
		foreach ($this->attributes as $attribute) {
			if (empty($this->owner->$attribute)) {
				continue;
			}

			$this->owner->$attribute = $this->uiToDb($this->owner->$attribute);
		}
	}

	protected function dbToUi($value): string
	{
		if ($this->dbFormat === 'unix') {
			$dt = new \DateTime('@' . $value);
			$dt->setTimezone(new \DateTimeZone($this->displayTimeZone));
		} else {
			$dt = new \DateTime(
				$value,
				new \DateTimeZone($this->serverTimeZone)
			);
			$dt->setTimezone(new \DateTimeZone($this->displayTimeZone));
		}

		return $dt->format($this->inputFormat);
	}

	protected function uiToDb(string $value)
	{
		$dt = \DateTime::createFromFormat(
			$this->inputFormat,
			$value,
			new \DateTimeZone($this->displayTimeZone)
		);

		if ($dt === false) {
			return null;
		}

		$dt->setTimezone(new \DateTimeZone($this->serverTimeZone));

		return $this->dbFormat === 'unix'
			? $dt->getTimestamp()
			: $dt->format('Y-m-d H:i:s');
	}
}
