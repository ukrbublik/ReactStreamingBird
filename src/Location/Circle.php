<?php

namespace Ukrbublik\ReactStreamingBird\Location;

class Circle implements LocationInterface
{
    const EARTH_RADIUS_KM  = 6371;

    /**
     * @var float
     */
    private $radius;

    /**
     * @var float
     */
    private $latitude;

    /**
     * @var float
     */
    private $longitude;

    /**
     * @param float $longitude
     * @param float $latitude
     * @param float $radius
     */
    public function __construct($longitude, $latitude, $radius)
    {
        $this->longitude = $longitude;
        $this->latitude  = $latitude;
        $this->radius    = $radius;
    }

    /**
     * {@inheritDoc}
     */
    public function getBoundingBox()
    {
        // Calc bounding boxes
        $maxLat = round($this->latitude + rad2deg($this->radius / self::EARTH_RADIUS_KM), 2);
        $minLat = round($this->latitude - rad2deg($this->radius / self::EARTH_RADIUS_KM), 2);

        // Compensate for degrees longitude getting smaller with increasing latitude
        $maxLon = round($this->longitude + rad2deg($this->radius / self::EARTH_RADIUS_KM / cos(deg2rad($this->latitude))), 2);
        $minLon = round($this->longitude - rad2deg($this->radius / self::EARTH_RADIUS_KM / cos(deg2rad($this->latitude))), 2);

        return [$minLon, $minLat, $maxLon, $maxLat];
    }
}
