
    /**
     * Validates the input by mapping it, with the identifier the server sets, into the DTO. Nothing is stored: the
     * rows above are fixed, and a worker keeps nothing between requests. Store {{pluralWords}} with a driver, such as
     * Doctrine (https://kipchak.dev/docs/driver-doctrine) or CouchDB (https://kipchak.dev/docs/driver-couchdb).
     *
     * @param array<string, mixed> $input
     * @throws MappingError when the input does not match the DTO
     */
    public function create(array $input): {{dto}}
    {
        unset($input['id']);

        return $this->map(['id' => count(self::{{constant}}) + 1] + $input);
    }

    /**
     * Replaces a {{singularWords}} with the input, validated as create() validates it. Null when there is none with
     * that identifier.
     *
     * @param array<string, mixed> $input
     * @throws MappingError when the input does not match the DTO
     */
    public function update(int $id, array $input): ?{{dto}}
    {
        if ($this->find($id) === null) {
            return null;
        }
        unset($input['id']);

        return $this->map(['id' => $id] + $input);
    }

    /**
     * Whether there was a {{singularWords}} with that identifier to delete.
     */
    public function delete(int $id): bool
    {
        return $this->find($id) !== null;
    }
