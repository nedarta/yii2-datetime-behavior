<?php

namespace yii\base;

class Component
{
    private $_events = [];

    public function trigger($name, $event = null) {
        if (!isset($this->_events[$name])) {
            return;
        }
        foreach ($this->_events[$name] as $handler) {
            call_user_func($handler, $event);
        }
    }

    public function on($name, $handler, $data = null, $append = true) {
        $this->_events[$name][] = $handler;
    }

    public function off($name, $handler = null) {
        // Not needed for this test
    }
}

class Behavior extends Component
{
    public $owner;
    public function attach($owner)
    {
        $this->owner = $owner;
        foreach ($this->events() as $event => $handler) {
            $owner->on($event, [$this, $handler]);
        }
    }
    public function events() { return []; }
}

class Model extends Component
{
    private $_behaviors = [];
    
    public function getBehavior($name) {
        return $this->_behaviors[$name] ?? null;
    }
    
    // Minimal behavior support for test
    public function __construct() {
        $this->init();
    }
    
    public function init() {
        if (method_exists($this, 'behaviors')) {
            foreach ($this->behaviors() as $name => $config) {
                // Instantiate behavior
                $class = $config['class'];
                $behavior = new $class();
                foreach ($config as $key => $value) {
                    if ($key !== 'class') $behavior->$key = $value;
                }
                $this->_behaviors[$name] = $behavior;
                $behavior->attach($this);
            }
        }
    }

    public function validate() { return true; }
}

class ModelEvent
{
    public $isValid = true;
    public $name;
    public $sender;
    public $handled = false;
    public $data;
}

namespace yii\db;

use yii\base\Model;

class ActiveRecord extends Model
{
    const EVENT_AFTER_FIND = 'afterFind';
    const EVENT_BEFORE_INSERT = 'beforeInsert';
    const EVENT_BEFORE_UPDATE = 'beforeUpdate';
    const EVENT_BEFORE_VALIDATE = 'beforeValidate';
    const EVENT_AFTER_VALIDATE = 'afterValidate';
}

namespace nedarta\behaviors;
// Allow the class to be loaded via require since we don't have autoloader for it in this context
// We will manually require src/DateTimeBehavior.php in the test runner
