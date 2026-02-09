<?php

namespace nedarta\behaviors\tests;

use nedarta\behaviors\DateTimeBehavior;
use Yii;
use yii\db\ActiveRecord;

/**
 * Test Model for DateTimeBehavior
 * 
 * @coversDefaultClass \nedarta\behaviors\DateTimeBehavior
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
        
        // Set default App timezone to 'Europe/Riga' to match what most tests expect.
        // Since precedence is now App > Behavior, we must control the App timezone to test localized outputs.
        Yii::$app->timeZone = 'Europe/Riga';
        if (Yii::$app->has('formatter')) {
            Yii::$app->formatter->timeZone = null;
        }

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

    /**
     * @covers ::beforeSave
     * @covers ::parseInput
     * @covers ::formatForDb
     * @covers ::isDbFormat
     * @covers ::isEmpty
     */
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

    /**
     * @covers ::afterFind
     * @covers ::createDateTimeFromDb
     * @covers ::getDisplayTimeZone
     */
    public function testDbToUiUnix()
    {
        // Insert raw data
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => 1704103200, // 10:00 UTC
        ])->execute();

        $model = TestActiveRecord::find()->one();
        $this->assertEquals('2024-01-01 12:00 +02:00', $model->created_at, 'Should convert to Riga time with offset');
    }

    /**
     * @covers ::afterFind
     * @covers ::beforeSave
     * @covers ::formatForDb
     */
    public function testUiToDbDateTime()
    {
        $model = $this->getModel('datetime');
        $model->created_at = '2024-01-01 12:00'; // Riga
        $model->save(false);

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals('2024-01-01 10:00:00', $inDb['created_at'], 'Database should contain UTC datetime string');
    }

    /**
     * @covers ::afterFind
     * @covers ::createDateTimeFromDb
     */
    public function testDbToUiDateTime()
    {
        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => '2024-01-01 10:00:00', // UTC
        ])->execute();

        $model = TestActiveRecordDateTime::find()->one();
        $this->assertEquals('2024-01-01 12:00 +02:00', $model->created_at, 'Should convert to Riga time with offset');
    }

    /**
     * @covers ::afterFind
     * @covers ::beforeSave
     * @covers ::createDateTimeFromDb
     * @covers ::parseInput
     */
    public function testAsiaTokyo()
    {
        // 10:00 UTC -> 19:00 Tokyo (JST is UTC+9)
        // With new precedence (App > Behavior), existing behavior property is ignored.
        // We must explicitly set App Timezone to achieve the desired localized output.
        $oldAppTz = Yii::$app->timeZone;
        Yii::$app->timeZone = 'Asia/Tokyo';

        Yii::$app->db->createCommand()->insert('test_active_record', [
            'created_at' => 1704103200, // 10:00 UTC
        ])->execute();

        $model = TestActiveRecordTokyo::find()->one();
        
        $this->assertEquals('2024-01-01 19:00 +09:00', $model->created_at, 'Should convert to Tokyo time (+9)');
        
        // Restore
        Yii::$app->timeZone = $oldAppTz;
    }

    /**
     * @covers ::getDisplayTimeZone
     */
    public function testFormatterPrecedence()
    {
        // 1. App = UTC, Formatter = Riga. 
        // Logic should pick Formatter (Riga) over App (UTC)
        
        $oldAppTz = Yii::$app->timeZone;
        Yii::$app->timeZone = 'UTC';

        $originalFormatter = Yii::$app->has('formatter') ? Yii::$app->get('formatter') : null;
        Yii::$app->set('formatter', [
            'class' => 'yii\i18n\Formatter',
            'timeZone' => 'Europe/Riga',
        ]);
        
        // Use a model that has NO specific TZ set (fallback to global)
        $model = new TestActiveRecordFallback();
        
        // Insert 10:00 UTC
        Yii::$app->db->createCommand()->insert('test_active_record', [
             'created_at' => 1704103200, 
        ])->execute();
        
        $found = TestActiveRecordFallback::find()->one();
        
        // Should be 12:00 Riga (+2)
        $this->assertEquals('2024-01-01 12:00 +02:00', $found->created_at, 'Formatter TZ should take precedence over App TZ');

        // Cleanup
        Yii::$app->timeZone = $oldAppTz;
        if ($originalFormatter) {
            Yii::$app->set('formatter', $originalFormatter);
        } else {
            Yii::$app->clear('formatter');
        }
    }

    /**
     * @covers ::getDisplayTimeZone
     */
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

    /**
     * @covers ::beforeSave
     * @covers ::formatForDb
     */
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


    /**
     * @covers ::beforeSave
     * @covers ::formatForDb
     */
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

    /**
     * @covers ::beforeSave
     * @covers ::formatForDb
     */
    public function testCustomOutput()
    {
        $model = $this->getModel('custom');
        $model->created_at = '2024-01-01 12:00'; // Riga
        $model->save(false);

        $inDb = (new \yii\db\Query())->from('test_active_record')->where(['id' => $model->id])->one();
        $this->assertEquals('20240101100000', $inDb['created_at'], 'Database should contain custom format string');
    }

    /**
     * @covers ::normalize
     * @covers ::formatForDb
     */
    public function testBatchNormalization()
    {
        $model = $this->getModel('unix');
        $behavior = $model->getBehavior('dt');

        $data = [
            'name' => 'New Name',
            'created_at' => '2024-01-01 12:00', // Riga -> 10:00 UTC
        ];

        $normalized = $behavior->normalize($data);

        $this->assertEquals('New Name', $normalized['name']);
        $this->assertEquals(1704103200, $normalized['created_at'], 'Should normalize attribute in array');
    }

    /**
     * @covers ::normalize
     */
    public function testUpdateAll()
    {
        // 1. Setup existing records
        Yii::$app->db->createCommand()->insert('test_active_record', ['name' => 'A', 'created_at' => 0])->execute();
        Yii::$app->db->createCommand()->insert('test_active_record', ['name' => 'B', 'created_at' => 0])->execute();

        $model = $this->getModel('unix');
        $behavior = $model->getBehavior('dt');

        // 2. Normalize data for updateAll
        $updateData = $behavior->normalize([
            'name' => 'Updated',
            'created_at' => '2024-01-01 12:00', // Riga -> 10:00 UTC
        ]);

        // 3. Batch update
        TestActiveRecord::updateAll($updateData);

        // 4. Verify
        $records = (new \yii\db\Query())->from('test_active_record')->all();
        foreach ($records as $record) {
            $this->assertEquals('Updated', $record['name']);
            $this->assertEquals(1704103200, $record['created_at'], 'Batch update should use normalized UTC timestamp');
        }
    }

    /**
     * @covers ::afterFind
     * @covers ::createDateTimeFromDb
     */
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

    /**
     * @covers ::toTimestamp
     * @covers ::parseInput
     */
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

    /**
     * @covers ::beforeSave
     * @covers ::parseInput
     */
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

    /**
     * @covers ::beforeSave
     * @covers ::isDbFormat
     */
    public function testSafetyCheckPreFormatted()
    {
        $model = $this->getModel('unix');
        $model->created_at = 1704103200; // ALREADY Timestamp
        $model->save(false);
        
        $this->assertEquals(1704103200, $model->created_at, 'Should remain integer');
    }

    /**
     * @covers ::beforeValidate
     * @covers ::afterValidate
     */
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

    /**
     * @covers ::beforeSave
     * @covers ::formatForDb
     */
    public function testQueryFilteringMismatch()
    {
        // 1. It is 11:37 in Riga (+2)
        // 2. We have an event today at 12:30 in Riga
        
        $model = $this->getModel('datetime');
        $model->created_at = '2026-02-04 12:30'; // Entered in Riga time
        $model->save(false); // Stored as 10:30 UTC
        
        // 3. Current time in Riga is 11:37
        $nowRiga = '2026-02-04 11:37';
        
        // 4. Querying using Local Time (WRONG)
        // Comparison: 10:30 UTC string < 11:37 Local string string.
        $hidden = (new \yii\db\Query())
            ->from('test_active_record')
            ->where(['id' => $model->id])
            ->andWhere(['>', 'created_at', $nowRiga])
            ->one();
            
        $this->assertFalse((bool)$hidden, 'Event is hidden because we compared 10:30 UTC with 11:37 Local!');

        // 5. Querying using UTC Time (MANUAL RIGHT)
        $nowUtc = '2026-02-04 09:37';
        $visible = (new \yii\db\Query())
            ->from('test_active_record')
            ->where(['id' => $model->id])
            ->andWhere(['>', 'created_at', $nowUtc])
            ->one();
            
        $this->assertNotEmpty($visible, 'Event should be visible when comparing with UTC now!');

        // 6. Querying using toDbValue helper (AUTOMATIC RIGHT)
        $nowDb = $model->getBehavior('dt')->toDbValue('2026-02-04 11:37'); // Riga time as input
        $this->assertEquals('2026-02-04 09:37:00', $nowDb, 'Should convert local string to UTC DB string');

        $visibleHelper = (new \yii\db\Query())
            ->from('test_active_record')
            ->where(['id' => $model->id])
            ->andWhere(['>', 'created_at', $nowDb])
            ->one();

        $this->assertNotEmpty($visibleHelper, 'Event should be visible when using toDbValue helper!');
    }

    /**
     * @covers ::toDbValue
     * @covers ::formatForDb
     */
    public function testToDbValue()
    {
        $model = $this->getModel('unix');
        $behavior = $model->getBehavior('dt');

        // 'now' should return a numeric timestamp
        $this->assertIsInt($behavior->toDbValue('now'));

        // Local string should return UTC timestamp
        $this->assertEquals(1704103200, $behavior->toDbValue('2024-01-01 12:00')); // Riga 12:00 -> 10:00 UTC

        // Already DB format should remain same
        $this->assertEquals(1704103200, $behavior->toDbValue(1704103200));
        
        // Null/Empty
        $this->assertNull($behavior->toDbValue(''));
        $this->assertNull($behavior->toDbValue(null));
    }

    /**
     * @covers ::now
     */
    public function testStaticNow()
    {
        // For unix format
        $nowUnix = DateTimeBehavior::now(TestActiveRecord::class);
        $this->assertIsInt($nowUnix);
        $this->assertGreaterThan(1700000000, $nowUnix);

        // For datetime format (using Tokyo model)
        // NOTE: TestActiveRecordTokyo actually uses dbFormat => unix in current tests
        $nowTokyo = DateTimeBehavior::now(TestActiveRecordTokyo::class);
        $this->assertIsInt($nowTokyo);

        // For actual datetime format
        $nowDateTime = DateTimeBehavior::now(TestActiveRecordDateTime::class);
        $this->assertIsString($nowDateTime);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $nowDateTime);
    }
}
