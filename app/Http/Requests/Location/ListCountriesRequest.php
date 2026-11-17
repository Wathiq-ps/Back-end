<?php

namespace App\Http\Requests\Location;

class ListCountriesRequest extends ListLocationsRequest
{
    protected function sorts(): array
    {
        return ['name', '-name', 'iso2', '-iso2'];
    }
}
