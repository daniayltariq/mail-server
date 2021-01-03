<?php


namespace PBMail\Imap\Server;


class State
{

    protected $state;


    /**
     * Create a new instance of State.
     *
     * @param mixed ...$args
     * @return State
     */
    public static function make(...$args): State
    {
        return new static(...$args);
    }

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
     * Set a state option.
     *
     * @param string $state
     * @return mixed|null
     */
    public function get(string $state){
        return $this->state[ $state ] ?? null;
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

        if( is_string( $state ) ){
            $value = $args[ 1 ];
            $strict = $args[ 2 ] ?? false;
            return $strict ? $state === $value : $state == $value;
        }

        /**
         * If the state comparer is an array then we will check
         * for key value pairs as the state option and their values.
         */
        if( is_array( $state ) ){

            /**
             * The 2nd parameter will be used as strict comparison flag.
             */
            $strict = $value;


            /**
             * Return false if any of the value fails.
             * Returns true if all key-value pairs matches the state.
             */
            foreach ( $state as $key => $val ){
                if( !$this->is( $key, $val, $strict ) ){
                    return false;
                }
            }

            return true;
        }

        return false;

    }


}