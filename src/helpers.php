<?php

if (!function_exists('optional')) {
    function optional($value, $callback = null)
    {
        if (is_null($callback)) {
            return new class($value)
            {
                private $value;

                public function __construct($value)
                {
                    $this->value = $value;
                }

                public function __get($name)
                {
                    return is_null($this->value) ? null : $this->value->$name;
                }

                public function __call($name, $arguments)
                {
                    return is_null($this->value) ? null : $this->value->$name(...$arguments);
                }
            };
        }
        return is_null($value) ? null : $callback($value);
    }
}
