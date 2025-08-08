<?php

if (!function_exists('optional')) {
    /**
     * @template T
     * @param T|null $value
     * @param callable(T):mixed|null $callback
     * @return mixed
     */
    function optional($value, $callback = null)
    {
        if (is_null($callback)) {
            return new class ($value) {
                /** @var mixed */
                private $value;
                /** @param mixed $value */
                public function __construct($value)
                {
                    $this->value = $value;
                }
                /** @param string $name */
                public function __get($name)
                {
                    return is_null($this->value) ? null : $this->value->$name;
                }
                /**
                 * @param string $name
                 * @param array $arguments
                 * @return mixed
                 */
                public function __call($name, $arguments)
                {
                    return is_null($this->value) ? null : $this->value->$name(...$arguments);
                }
            };
        }
        return is_null($value) ? null : $callback($value);
    }
}
