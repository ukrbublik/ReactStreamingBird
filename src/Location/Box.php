<?php

namespace Ukrbublik\ReactStreamingBird\Location;

class Box implements LocationInterface
{
    /**
     * @var float
     */
    private $minLon;

    /**
     * @var float
     */
    private $minLat;

    /**
     * @var float
     */
    private $maxLon;

    /**
     * @var float
     */
    private $maxLat;

    /**
     * @param float $minLon
     * @param float $minLat
     * @param float $laxLon
     * @param float $maxLat
     */
    public function __construct($minLon, $minLat, $maxLon, $maxLat)
    {
        $this->minLon = $minLon;
        $this->minLat = $minLat;
        $this->maxLon = $maxLon;
        $this->maxLat = $maxLat;
    }

    /**
     * {@inheritDoc}
     */
    public function getBoundingBox()
    {
        return [$this->minLon, $this->minLat, $this->maxLon, $this->maxLat];
    }
}
