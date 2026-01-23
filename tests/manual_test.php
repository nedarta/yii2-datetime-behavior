<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use nedarta\behaviors\DateTimeBehavior;
use yii\base\Model;
use yii\db\ActiveRecord;

// Mock ActiveRecord just enough for our behavior
class DummyModel extends Model
{
    public $date_attr;
    
    // Behaviors support
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['date_attr'],
                'dbFormat' => 'unix', // Default
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'Europe/Riga',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
    
    public function save($runValidation = true, $attributeNames = null)
    {
        if ($runValidation && !$this->validate($attributeNames)) {
            return false;
        }
        
        // Trigger BEFORE_INSERT or BEFORE_UPDATE
        // We simulate INSERT here
        $event = new \yii\base\ModelEvent();
        $this->trigger(ActiveRecord::EVENT_BEFORE_INSERT, $event);
        
        if (!$event->isValid) {
            return false;
        }
        
        // "Save" to DB (mocking it)
        echo "Saving to DB: " . var_export($this->date_attr, true) . "\n";
        
        return true;
    }
    
    public function find()
    {
        // Simulate loading from DB
        echo "Loading from DB...\n";
        $this->trigger(ActiveRecord::EVENT_AFTER_FIND);
    }
}

// Helper to reset model
function createModel($dbFormat = 'unix', $displayTimeZone = 'Europe/Riga', $serverTimeZone = 'UTC') {
    $model = new DummyModel();
    // Reconfigure behavior for test
    $behavior = $model->getBehavior('dt');
    $behavior->dbFormat = $dbFormat;
    $behavior->displayTimeZone = $displayTimeZone;
    $behavior->serverTimeZone = $serverTimeZone;
    return $model;
}

echo "=== Test 1: UI Input -> DB (Unix) ===\n";
$model = createModel('unix');
$model->date_attr = '2024-01-01 12:00'; // Riga Time
echo "Input: {$model->date_attr}\n";

// Save
$model->save(false); // Skip validation to strictly test behavior logic
// Expected: 2024-01-01 12:00 Riga -> 10:00 UTC -> Timestamp 1704103200
$expected = 1704103200; 
if ($model->date_attr == $expected) {
    echo "SUCCESS: Converted to timestamp $expected\n";
} else {
    echo "FAILURE: Expected $expected, got {$model->date_attr}\n";
}

echo "\n=== Test 2: DB (Unix) -> UI Output ===\n";
$model = createModel('unix');
$model->date_attr = 1704103200; // 10:00 UTC
$model->find();
// Expected: 2024-01-01 12:00
$expected = '2024-01-01 12:00';
if ($model->date_attr === $expected) {
    echo "SUCCESS: Converted to string '$expected'\n";
} else {
    echo "FAILURE: Expected '$expected', got '{$model->date_attr}'\n";
}

echo "\n=== Test 3: UI Input -> DB (DateTime) ===\n";
$model = createModel('datetime');
$model->date_attr = '2024-01-01 12:00'; // Riga
// Save
$model->save(false);
// Expected: 2024-01-01 10:00:00 (UTC)
$expected = '2024-01-01 10:00:00';
if ($model->date_attr === $expected) {
    echo "SUCCESS: Converted to datetime '$expected'\n";
} else {
    echo "FAILURE: Expected '$expected', got '{$model->date_attr}'\n";
}

echo "\n=== Test 4: DB (DateTime) -> UI Output ===\n";
$model = createModel('datetime');
$model->date_attr = '2024-01-01 10:00:00'; // UTC
$model->find();
// Expected: 2024-01-01 12:00
$expected = '2024-01-01 12:00';
if ($model->date_attr === $expected) {
    echo "SUCCESS: Converted to string '$expected'\n";
} else {
    echo "FAILURE: Expected '$expected', got '{$model->date_attr}'\n";
}

echo "\n=== Test 5: UTC -> UTC (DateTIme) ===\n";
$model = createModel('datetime', 'UTC', 'UTC');
$model->date_attr = '2024-01-01 12:00'; // UTC
$model->save(false);
$expected = '2024-01-01 12:00:00';
if ($model->date_attr === $expected) {
    echo "SUCCESS: Converted to datetime '$expected'\n";
} else {
    echo "FAILURE: Expected '$expected', got '{$model->date_attr}'\n";
}
