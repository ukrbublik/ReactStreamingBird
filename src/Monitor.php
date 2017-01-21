<?php

namespace Ukrbublik\ReactStreamingBird;

class Monitor
{
    const TYPE_AVG   = 'avg';
    const TYPE_COUNT = 'count';
    const TYPE_LAST  = 'last';
    const TYPE_MAX   = 'max';
    const TYPE_MIN   = 'min';

    private $stats = [];

    /**
     * @param string    $type
     * @param string    $name
     * @param float|int $value
     */
    public function register($type, $name, $value = null)
    {
        if ($value === null) {
            if ($type === self::TYPE_MIN) {
                $value = PHP_INT_MAX;
            } else {
                $value = 0;
            }
        }


        $this->stats[$name] = [
            'type'          => $type,
            'value'         => $type === self::TYPE_COUNT ? 0 : $value,
            'originalValue' => $type === self::TYPE_COUNT ? 0 : $value,
            'count'         => 0
        ];
    }

    /**
     * @return string
     */
    public function getAllAsString()
    {
        return implode("\n", array_map(function ($name, $data) {
            return sprintf('%s = %s', $name, $data['value']);
        }, array_keys($this->stats), $this->stats));
    }

    /**
     * @param string $name
     *
     * @return float|int
     */
    public function get($name)
    {
        if (!$this->has($name)) {
            throw new \RuntimeException(sprintf('Cannot get non existing dimension "%s"', $name));
        }

        return $this->stats[$name]['value'];
    }

    /**
     * @param string    $name
     * @param float|int $value
     */
    public function stat($name, $value)
    {
        if (!$this->has($name)) {
            throw new \RuntimeException(sprintf('Cannot add stat for non existing dimension "%s"', $name));
        }

        switch ($this->stats[$name]['type']) {
            case self::TYPE_LAST:
                $this->stats[$name]['value'] = $value;
                break;
            case self::TYPE_COUNT:
                $this->stats[$name]['value']++;
                break;
            case self::TYPE_AVG:
                $this->stats[$name]['value'] = ($this->stats[$name]['value']*$this->stats[$name]['count'] + $value) / ($this->stats[$name]['count'] + 1);
                break;
            case self::TYPE_MIN:
                $this->stats[$name]['value'] = min($this->stats[$name]['value'], $value);
                break;
            case self::TYPE_MAX:
                $this->stats[$name]['value'] = max($this->stats[$name]['value'], $value);
                break;
        }

        $this->stats[$name]['count']++;
    }

    /**
     * @param string $name
     */
    public function clear($name)
    {
        $this->stats[$name]['value'] = $this->stats[$name]['originalValue'];
        $this->stats[$name]['count'] = 0;
    }

    /**
     * @param  string $name
     * @return boolean
     */
    public function has($name)
    {
        return array_key_exists($name, $this->stats);
    }
}
