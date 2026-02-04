<?php

namespace nedarta\behaviors\tests;

use nedarta\behaviors\DateTimeBehavior;
use Yii;
use yii\db\ActiveRecord;

/**
 * Test Model for DateTimeBehavior
 * 
 * @property int $id
 * @property string $name
 * @property int|string $created_at
 * @property int|string $updated_at
 */
class TestActiveRecord extends ActiveRecord
{
    public static function tableName()
    {
        return 'test_active_record';
    }

    public function rules()
    {
        return [
            [['name'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'unix', // Default
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'Europe/Riga',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
}

class TestActiveRecordDateTime extends TestActiveRecord
{
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'datetime',
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'Europe/Riga',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
}

class TestActiveRecordDate extends TestActiveRecord
{
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'date',
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'Europe/Riga',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
}

class TestActiveRecordTime extends TestActiveRecord
{
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'time',
                'inputFormat' => 'H:i',
                'displayTimeZone' => 'Europe/Riga',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
}

class TestActiveRecordCustom extends TestActiveRecord
{
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'YmdHis',
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'Europe/Riga',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
}

class TestActiveRecordTokyo extends TestActiveRecord
{
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'unix',
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => 'Asia/Tokyo',
                'serverTimeZone' => 'UTC',
            ]
        ];
    }
}

class TestActiveRecordFallback extends TestActiveRecord
{
    public function behaviors()
    {
        return [
            'dt' => [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at'],
                'dbFormat' => 'unix',
                // displayTimeZone is null by default now
            ]
        ];
    }
}

class DateTimeBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 1. Setup DB Table
        Yii::$app->db->createCommand()->createTable('test_active_record', [
            'id' => 'pk',
            'name' => 'string',
            'created_at' => 'string', // Use string to support both unix (as stringified int) and datetime strings in SQLite
            'updated_at' => 'integer',
        ])->execute();
    }

    protected function tearDown(): void
    {
        Yii::$app->db->createCommand()->dropTable('test_active_record')->execute();
        
        // Clean up error handlers
        while (set_error_handler(function() {})) {
            restore_error_handler();
            restore_error_handler();
        }
        
        while (set_exception_handler(function() {})) {
            restore_exception_handler();
            restore_exception_handler();
        }
        
        parent::tearDown();
    }

    protected function getModel($dbFormat = 'unix')
    {
        return match ($dbFormat) {
            'datetime' => new TestActiveRecordDateTime(),
            'date' => new TestActiveRecordDate(),
            'time' => new TestActiveRecordTime(),
            'custom' => new TestActiveRecordCustom(),
            'tokyo' => new TestActiveRecordTokyo(),
            'fallback' => new TestActiveRecordFallback(),
            default => new TestActiveRecord(),
        };
    }

    public function testUiToDbUnix()
    {
        $model = $this->getModel('unix');
        $model->created_at = '2024-01-01 12:00'; // Riga Time (UTC+2) -> 10:00 UTC
        $model->save(false);

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals(1704103200, $inDb['created_at'], 'Database should contain UTC timestamp');
        
        // Check model attribute is updated to timestamp after save? 
        // Actually behavior only converts it. After save, the attribute remains what it was converted to.
        $this->assertEquals(1704103200, $model->created_at);
    }

    public function testDbToUiUnix()
    {
        // Insert raw data
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => 1704103200, // 10:00 UTC
        ])->execute();

        $model = TestActiveRecord::find()->one();
        $this->assertEquals('2024-01-01 12:00 +02:00', $model->created_at, 'Should convert to Riga time with offset');
    }

    public function testUiToDbDateTime()
    {
        $model = $this->getModel('datetime');
        $model->created_at = '2024-01-01 12:00'; // Riga
        $model->save(false);

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals('2024-01-01 10:00:00', $inDb['created_at'], 'Database should contain UTC datetime string');
    }

    public function testDbToUiDateTime()
    {
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => '2024-01-01 10:00:00', // UTC
        ])->execute();

        $model = TestActiveRecordDateTime::find()->one();
        $this->assertEquals('2024-01-01 12:00 +02:00', $model->created_at, 'Should convert to Riga time with offset');
    }

    public function testAsiaTokyo()
    {
        // 10:00 UTC -> 19:00 Tokyo (JST is UTC+9)
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => 1704103200, // 10:00 UTC
        ])->execute();

        $model = TestActiveRecordTokyo::find()->one();
        $this->assertEquals('2024-01-01 19:00 +09:00', $model->created_at, 'Should convert to Tokyo time (+9)');
    }

    public function testGlobalConfigFallback()
    {
        // Mock global timezone
        $oldTz = Yii::$app->timeZone;
        Yii::$app->timeZone = 'Asia/Tokyo';
        
        // 10:00 UTC -> 19:00 Tokyo
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => 1704103200,
        ])->execute();

        $model = TestActiveRecordFallback::find()->one();
        
        // Restore before assertion in case it fails
        Yii::$app->timeZone = $oldTz;

        $this->assertEquals('2024-01-01 19:00 +09:00', $model->created_at, 'Should fallback to Yii::$app->timeZone');
    }

    public function testDateOutput()
    {
        $model = $this->getModel('date');
        $model->created_at = '2024-01-01 12:00'; // Riga
        $model->save(false);

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals('2024-01-01', $inDb['created_at'], 'Database should contain UTC date string');

        // Load back
        $model = TestActiveRecordDate::findOne($model->id);
        $this->assertEquals('2024-01-01 02:00 +02:00', $model->created_at, 'Should handle date format (time resets to 00:00 UTC)');
    }


    public function testTimeOutput()
    {
        $model = $this->getModel('time');
        $input = '12:00';
        $model->created_at = $input; // Riga
        $model->save(false);

        $dt = \DateTime::createFromFormat('!H:i', $input, new \DateTimeZone('Europe/Riga'));
        $dt->setTimezone(new \DateTimeZone('UTC'));
        $expected = $dt->format('H:i:s');

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals($expected, $inDb['created_at'], 'Database should contain calculated UTC time string');
    }

    public function testCustomOutput()
    {
        $model = $this->getModel('custom');
        $model->created_at = '2024-01-01 12:00'; // Riga
        $model->save(false);

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals('20240101100000', $inDb['created_at'], 'Database should contain custom format string');
    }

    public function testZeroTimestampUnix()
    {
        // DB has 0
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => 0,
        ])->execute();

        $model = TestActiveRecord::find()->one();
        // 1970-01-01 00:00 UTC -> Riga (+3 in 1970 apparently? or +2? PHP knows).
        // Let's calculate expected
        $bg = new \DateTime('@0');
        $bg->setTimezone(new \DateTimeZone('Europe/Riga'));
        $expected = $bg->format('Y-m-d H:i P');

        $this->assertEquals($expected, $model->created_at, 'Should handle zero timestamp with offset');
        $this->assertNotEmpty($model->created_at);
    }

    public function testToTimestamp()
    {
        $model = $this->getModel('unix');
        
        // From UI format (no offset)
        $model->created_at = '2024-01-01 12:00';
        $this->assertEquals(1704103200, $model->getBehavior('dt')->toTimestamp('created_at'));

        // From Display format (with offset)
        $model->created_at = '2024-01-01 12:00 +02:00';
        $this->assertEquals(1704103200, $model->getBehavior('dt')->toTimestamp('created_at'));

        // Raw timestamp
        $model->created_at = 1704103200;
        $this->assertEquals(1704103200, $model->getBehavior('dt')->toTimestamp('created_at'));

        // Null/Empty
        $model->created_at = null;
        $this->assertNull($model->getBehavior('dt')->toTimestamp('created_at'));
    }

    public function testUiInputVariations()
    {
        $model = $this->getModel('unix');
        
        // Input without offset (from form)
        $model->created_at = '2024-01-01 12:00';
        $model->save(false);
        $this->assertEquals(1704103200, $model->created_at, 'Should parse input without offset');

        // Input with offset (re-saving same value)
        $model->created_at = '2024-01-01 12:00 +02:00';
        $model->save(false);
        $this->assertEquals(1704103200, $model->created_at, 'Should parse input with offset');
    }

    public function testSafetyCheckPreFormatted()
    {
        $model = $this->getModel('unix');
        $model->created_at = 1704103200; // ALREADY Timestamp
        $model->save(false);
        
        $this->assertEquals(1704103200, $model->created_at, 'Should remain integer');
    }

    public function testValidationRestore()
    {
        $model = $this->getModel('unix');
        $input = 'invalid-date-garbage';
        $model->created_at = $input;

        // validation rule 'safe' allows anything, but let's assume we wanted to fail?
        // actually existing rules are 'safe'.
        // To test restoration, we need validation to FAIL.
        // Let's manually add an error or add a rule that fails.
        
        // We can attach a dynamic validator or just mock return of validate() if we could.
        // But since we use real AR, let's just make it fail validation using a rule on the fly?
        $model->getErrorSummary(true); // clear
        $model->addError('created_at', 'Fake error');
        
        // We need to trigger the sequence: beforeValidate -> (validation fails) -> afterValidate
        
        // 1. Manually call beforeValidate behavior event
        $model->trigger(ActiveRecord::EVENT_BEFORE_VALIDATE);
        
        // 2. We pretend validation happened and failed (we added manual error above)
        // 3. Manually call afterValidate
        $model->trigger(ActiveRecord::EVENT_AFTER_VALIDATE);
        
        $this->assertEquals($input, $model->created_at, 'Should be restored to original garbage input');
    }
}
