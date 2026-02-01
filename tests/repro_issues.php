<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
} else {
    require_once __DIR__ . '/stubs.php';
    require_once __DIR__ . '/../src/DateTimeBehavior.php';
}

use nedarta\behaviors\DateTimeBehavior;
use yii\base\Model;
use yii\db\ActiveRecord;

// Mock ActiveRecord
class ReproModel extends Model
{
    public $date_attr;
    public $shouldFailSave = false; // Trigger to simulate DB failure
    
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['date_attr'],
                'dbFormat' => 'unix',
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'UTC', // Simplify timezone for repro
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
    
    // Simulate ActiveRecord::save flow
    public function save($runValidation = true)
    {
        if ($runValidation && !$this->validate()) {
            return false;
        }
        
        // Event: BEFORE_INSERT
        $event = new \yii\base\ModelEvent();
        $this->trigger(ActiveRecord::EVENT_BEFORE_INSERT, $event);
        
        if (!$event->isValid) {
            return false;
        }
        
        // Simulate DB Insert
        if ($this->shouldFailSave) {
            // DB Error happens here, insert returns false
            // But attributes are already modified by BEFORE_INSERT
            return false;
        }
        
        return true;
    }
    
    public function find()
    {
        $this->trigger(ActiveRecord::EVENT_AFTER_FIND);
    }
}

echo "=== Repro 1: Timestamp 0 (1970-01-01) ===\n";
$model = new ReproModel();
$model->date_attr = 0; // DB value
$model->find();
// Expected: '1970-01-01 00:00' (if inputFormat is Y-m-d H:i)
// Current behavior assumption: isEmpty(0) returns true, so it skips conversion.
echo "DB Value: 0\n";
echo "Model Value after find(): " . var_export($model->date_attr, true) . "\n";
if ($model->date_attr === 0) {
    echo "FAIL: Value remained 0, was not formatted.\n";
} else {
    echo "SUCCESS: Value formatted to '{$model->date_attr}'.\n";
}

echo "\n=== Repro 2: Failed Save Leaves Dirty Attribute ===\n";
$model = new ReproModel();
$model->date_attr = '2023-01-01 12:00'; // User input
$model->shouldFailSave = true;

echo "Original Input: {$model->date_attr}\n";
$result = $model->save(false); // Skip validation, go straight to save -> beforeSave
echo "Save Result: " . ($result ? 'true' : 'false') . "\n";
echo "Model Value after failed save: " . var_export($model->date_attr, true) . "\n";

// If validation/save failed, we want the user to still see '2023-01-01 12:00' in the form.
// But behaviors converted it to timestamp.
if (is_numeric($model->date_attr)) {
    echo "FAIL: Value is converted to timestamp '{$model->date_attr}'. User will see this in form.\n";
} else {
    echo "SUCCESS: Value preserved as '{$model->date_attr}'.\n";
}


echo "\n=== Repro 3: Re-saving converted value (Idempotency) ===\n";
// Continuing from Repro 2... model->date_attr is now a timestamp (e.g. 1672574400)
// If we call save() again (e.g. retry), what happens?
$model->shouldFailSave = false; // Let it succeed this time
$prevValue = $model->date_attr;
echo "Retrying save with value: " . var_export($prevValue, true) . "\n";

$model->save(false);
echo "Model Value after second save: " . var_export($model->date_attr, true) . "\n";

// If it treated the timestamp as valid input or recognized it as already converted, it should remain a timestamp.
// But if it tried to parse it as date and failed, it might skip (which is good).
// But we want to ensure it doesn't try to double-convert or throw error.
// The issue states "behavior might try to convert already-converted values again".
// If dbFormat is 'unix', it expects int. If we pass int, createDateTimeFromDb? No, beforeSave uses createFromFormat(inputFormat).
// 'Y-m-d H:i' parsing '1672574400' -> returns false?
$date = DateTime::createFromFormat('Y-m-d H:i', (string)$prevValue);
if ($date === false) {
    echo "Behavior Analysis: createFromFormat failed on timestamp (Expected behavior currently).\n";
} else {
    echo "Behavior Analysis: createFromFormat SUCCEEDED? -> " . $date->format(DateTime::ATOM) . "\n";
}
// If it skips, $model->date_attr stays as timestamp. This is actually "safe" for unix format.
// But what if dbFormat is 'datetime' and we have '2023-01-01 12:00:00' (DB format) but input is 'Y-m-d H:i'?
// It might parse slightly differently or fail.

