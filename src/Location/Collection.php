<?php

namespace Ukrbublik\ReactStreamingBird\Location;

class Collection implements LocationInterface
{
    /**
     * @var LocationInterface[]
     */
    private $locations;

    /**
     * @param LocationInterface[] $locations
     */
    public function __construct(array $locations)
    {
        $this->locations = $locations;
    }

    /**
     * {@inheritDoc}
     */
    public function getBoundingBox()
    {
        $box = [];

        foreach ($this->locations as $location) {
            $box = array_merge($box, $location->getBoundingBox());
        }

        return $box;
    }
}
