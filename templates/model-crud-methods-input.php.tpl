
    /**
     * The input has been validated by Dto\Input in the controller. Nothing is stored: the rows above are fixed, and a
     * worker keeps nothing between requests. Store {{pluralWords}} with a driver, such as Doctrine
     * (https://kipchak.dev/docs/driver-doctrine) or CouchDB (https://kipchak.dev/docs/driver-couchdb).
     */
    public function create({{dto}} $input): {{dto}}
    {
        return new {{dto}}(...['id' => count(self::{{constant}}) + 1] + get_object_vars($input));
    }

    /**
     * Replaces a {{singularWords}} with the input. Null when there is none with that identifier.
     */
    public function update(int $id, {{dto}} $input): ?{{dto}}
    {
        if ($this->find($id) === null) {
            return null;
        }

        return new {{dto}}(...['id' => $id] + get_object_vars($input));
    }

    /**
     * Whether there was a {{singularWords}} with that identifier to delete.
     */
    public function delete(int $id): bool
    {
        return $this->find($id) !== null;
    }
