<?php


namespace PBMail\Imap\Server;


use PBMail\Imap\Server\Traits\Makeable;

class State
{

    use Makeable;

    protected $state;

    /**
     * Create a new State.
     *
     * @param array $stateOptions The default state options.
     */
    public function __construct($stateOptions = []){
        $this->state = $stateOptions;
    }


    /**
     * Set a state option.
     *
     * @param string $state
     * @param $value
     */
    public function set(string $state, $value){
        $this->state[ $state ] = $value;
    }

    /**
     * Unset a state option.
     *
     * @param string $state
     */
    public function unset(string $state){
        if( $this->state[ $state ] ?? false ){
            unset( $this->state[ $state ] );
        }
    }

    /**
     * Set a state option.
     *
     * @param string $state
     * @param null $default
     * @return mixed|null Returns the state value or $default if state does not exist.
     */
    public function get(string $state, $default = null){
        return $this->state[ $state ] ?? $default;
    }


    /**
     * Check if a state option has a specific value.
     *
     * Send either an array with key value pairs or string in
     * the first parameter and value in second parameter.
     *
     * The last parameter (boolean) is for strict comparison.
     * By default it is set to false.
     *
     * If using array the 2nd parameter will be used as strict
     * comparison flag.
     *
     * @param string|array $state
     * @param mixed $value
     * @param mixed ...$args
     * @return bool
     */
    public function is($state, $value, ...$args): bool
    {

        if( is_scalar( $state ) ){
            $strict = boolval( reset( $args ) );
            $currentState = $this->state[ $state ] ?? null;
            return $strict ? $currentState === $value : $currentState == $value;
        }


        /**
         * If the state comparer is an array or object cast to an
         * array then we will check for key value pairs as the state
         * option and their values.
         *
         * For this case, the 2nd parameter will be used as strict
         * comparison flag.
         */

        $strict = $value;


        /**
         * Return false if any of the value fails.
         * Returns true if all key-value pairs matches the state.
         */
        foreach ( (array) $state as $key => $val ) {
            if (!$this->is($key, $val, $strict)) {
                return false;
            }
        }

        return true;

    }


    /**
     * Get the full state.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->state;
    }


    /**
     * Increment the value of a step.
     *
     * @param string $state
     * @param int|float $by
     * @return float|int
     */
    public function incr(string $state, $by = 1)
    {
        return $this->state[ $state ] += $by;
    }


    /**
     * Increment the value of a step.
     *
     * @param string $state
     * @param int|float $by
     * @return float|int
     */
    public function decr(string $state, $by = 1)
    {
        return $this->incr( - $by );
    }


    /**
     * Append a string to a state value.
     *
     * @param string $state
     * @param string $string
     * @return float|int
     */
    public function append(string $state, string $string)
    {
        return $this->state[ $state ] .= $string;
    }

    /**
     * Prepend a string to a state value.
     *
     * @param string $state
     * @param string $string
     * @return float|int
     */
    public function prepend(string $state, string $string)
    {
        return $this->state[ $state ] = $string . $this->state[ $state ];
    }


}