<?php

namespace Swoole;
class ArrayKeyNotExists
{

    public static function check(array $array, string $key): bool
    {
        return !array_key_exists($key, $array);
    }

    public static function checkMultiple(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) return true;
        }
        return false;
    }

    public static function checkMultipleStrict(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) return false;
        }
        return true;
    }

    public static function checkMultipleStrictAnd(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) return false;
        }
        return true;
    }

    public static function checkMultipleStrictOr(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) return true;
        }
        return false;
    }

    public static function checkMultipleAnd(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) return true;
        }
        return false;
    }

    public static function checkMultipleOr(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) return true;
        }
        return false;
    }

    public static function checkMultipleNot(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) return false;
        }
        return true;
    }

    public static function checkMultipleNotStrict(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) return false;
        }
        return true;
    }

    public static function checkMultipleNotStrictAnd(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) return false;
        }
        return true;
    }
}