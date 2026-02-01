<?php

// 1. Load Stubs and Class
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../src/DateTimeBehavior.php';

use nedarta\behaviors\DateTimeBehavior;
use yii\base\Model;
use yii\db\ActiveRecord;

// 2. Setup Dummy Model using Stubs
class TestModel extends Model
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
    
    // Minimal save simulation
    public function save($runValidation = true)
    {
        // 1. Validation phase
        if ($runValidation) {
            $event = new \yii\base\ModelEvent();
            $this->trigger(ActiveRecord::EVENT_BEFORE_VALIDATE, $event);
            
            // Simulate triggering validation rules (none here, but behavior restores on failure)
            // We won't simulate failure here unless testing specifically for it
            
            $this->trigger(ActiveRecord::EVENT_AFTER_VALIDATE, $event);
        }
        
        // 2. Save phase
        $event = new \yii\base\ModelEvent();
        $this->trigger(ActiveRecord::EVENT_BEFORE_INSERT, $event);
        
        if (!$event->isValid) {
            return false;
        }
        
        // "Saved"
        return true;
    }
    
    public function find()
    {
        $this->trigger(ActiveRecord::EVENT_AFTER_FIND);
    }
}

// Helper to check for hasErrors support in tests that need it
class TestModelWithValidation extends TestModel {
    public $_errors = [];
    public function hasErrors($attribute = null) {
        if ($attribute === null) return !empty($this->_errors);
        return isset($this->_errors[$attribute]);
    }
    public function addError($attribute, $error) {
        $this->_errors[$attribute][] = $error;
    }
}

// Helper
function createModel($dbFormat = 'unix', $displayTimeZone = 'Europe/Riga', $serverTimeZone = 'UTC') {
    $model = new TestModel();
    $behavior = $model->getBehavior('dt');
    $behavior->dbFormat = $dbFormat;
    $behavior->displayTimeZone = $displayTimeZone;
    $behavior->serverTimeZone = $serverTimeZone;
    return $model;
}

function assertVal($actual, $expected, $message) {
    if ($actual === $expected) {
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message. Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
    }
}

echo "=== Running Standalone Tests ===\n";

// --- Original Logic Regression Tests ---

// Test 1: UI Input -> DB (Unix)
$model = createModel('unix');
$model->date_attr = '2024-01-01 12:00'; // Riga Time
$model->save(false);
// 12:00 Riga is 10:00 UTC. 1704103200
assertVal($model->date_attr, 1704103200, "UI(Riga) -> DB(Unix Timestamp)");

// Test 2: DB (Unix) -> UI Output
$model = createModel('unix');
$model->date_attr = 1704103200; // 10:00 UTC
$model->find();
assertVal($model->date_attr, '2024-01-01 12:00', "DB(Unix) -> UI(Riga)");

// Test 3: UI Input -> DB (DateTime)
$model = createModel('datetime');
$model->date_attr = '2024-01-01 12:00'; // Riga
$model->save(false);
assertVal($model->date_attr, '2024-01-01 10:00:00', "UI(Riga) -> DB(DateTime UTC)");


// --- New Feature Tests ---

// Test 4: 0 Timestamp (Unix)
$model = createModel('unix');
$model->date_attr = 0; // The beginning of epoch
$model->find();
// 0 is 1970-01-01 00:00:00 UTC. In Riga it was ... well, let's just check it parses.
// Riga is UTC+1/UTC+2 (EET/EEST). In 1970 ... likely +3 due to Soviet time?
// Actually PHP handles historical timezones.
// Let's just check it is NOT ignored (previously would stay 0 or empty)
$dt = new DateTime('@0', new DateTimeZone('UTC'));
$dt->setTimezone(new DateTimeZone('Europe/Riga'));
$expected = $dt->format('Y-m-d H:i');
assertVal($model->date_attr, $expected, "DB(0) -> UI(Riga) [Should not be skipped/empty]");

// Test 5: Safety Check - Pre-formatted (Unix)
$model = createModel('unix');
$model->date_attr = 1704103200; // Already in DB format
$model->save(false);
assertVal($model->date_attr, 1704103200, "Safety Check: Already Unix Timestamp - preserved");

// Test 6: Safety Check - Pre-formatted (DateTime)
$model = createModel('datetime');
$model->date_attr = '2024-01-01 10:00:00'; // Already in DB format (UTC)
$model->save(false);
assertVal($model->date_attr, '2024-01-01 10:00:00', "Safety Check: Already DateTime String - preserved");

// Test 7: Validation Restore Logic
$model = new TestModelWithValidation();
// Attach behavior
$behavior = $model->getBehavior('dt');
$behavior->dbFormat = 'unix';

$input = 'invalid-date-string';
$model->date_attr = $input; // Input garbage

// 1. Store original
$model->trigger(ActiveRecord::EVENT_BEFORE_VALIDATE, new \yii\base\ModelEvent());

// 2. Simulate validation error
$model->addError('date_attr', 'Invalid date');

// 3. Trigger restore
$model->trigger(ActiveRecord::EVENT_AFTER_VALIDATE, new \yii\base\ModelEvent());

assertVal($model->date_attr, $input, "Validation Restore: Original value restored on error");
