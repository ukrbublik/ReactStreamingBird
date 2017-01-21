<?php

namespace Ukrbublik\ReactStreamingBird\Location;

interface LocationInterface
{
    /**
     * @return float[]
     */
    public function getBoundingBox();
}
