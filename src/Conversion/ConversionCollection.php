<?php

namespace Spatie\MediaLibrary\Conversion;

use Illuminate\Support\Arr;
use Spatie\Image\Manipulations;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\Models\Media;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\MediaLibrary\Exceptions\InvalidConversion;

class ConversionCollection extends Collection
{
    /** @var \Spatie\MediaLibrary\Models\Media */
    protected $media;

    /**
     * @param \Spatie\MediaLibrary\Models\Media $media
     *
     * @return static
     */
    public static function createForMedia(Media $media)
    {
        return (new static())->setMedia($media);
    }

    /**
     * @param \Spatie\MediaLibrary\Models\Media $media
     *
     * @return $this
     */
    public function setMedia(Media $media)
    {
        $this->media = $media;

        $this->items = [];

        $this->addConversions($media);

        $this->addManipulationsFromDb($media);

        return $this;
    }

    /**
     *  Get a conversion by it's name.
     *
     * @param string $name
     *
     * @return mixed
     *
     * @throws \Spatie\MediaLibrary\Exceptions\InvalidConversion
     */
    public function getByName(string $name): Conversion
    {
        $conversion = $this->first(function (Conversion $conversion) use ($name) {
            return $conversion->getName() === $name;
        });

        if (! $conversion) {
            throw InvalidConversion::unknownName($name);
        }

        return $conversion;
    }

    /**
     * Add the conversions that are defined on the Media model, or on the related model.
     *
     * @param \Spatie\MediaLibrary\Models\Media $media
     * @return void
     */
    protected function addConversions(Media $media)
    {
        $media->registerAllMediaConversions();

        $this->items = $media->mediaConversions;

        if ($media->hasModel()) {
            $this->addConversionsFromRelatedModel($media);
        }
    }

    /**
     * Add the conversion that are defined on the related model of
     * the given media.
     *
     * @param \Spatie\MediaLibrary\Models\Media $media
     */
    protected function addConversionsFromRelatedModel(Media $media)
    {
        $modelName = Arr::get(Relation::morphMap(), $media->model_type, $media->model_type);

        /** @var \Spatie\MediaLibrary\HasMedia\HasMedia $model */
        $model = new $modelName();

        /*
         * In some cases the user might want to get the actual model
         * instance so conversion parameters can depend on model
         * properties. This will causes extra queries.
         */
        if ($model->registerMediaConversionsUsingModelInstance) {
            $model = $media->model;

            $model->mediaConversion = [];
        }

        $model->registerAllMediaConversions($media);

        $this->items = array_merge($this->items, $model->mediaConversions);
    }

    /**
     * Add the extra manipulations that are defined on the given media.
     *
     * @param \Spatie\MediaLibrary\Models\Media $media
     */
    protected function addManipulationsFromDb(Media $media)
    {
        collect($media->manipulations)->each(function ($manipulations, $conversionName) {
            $this->addManipulationToConversion(new Manipulations([$manipulations]), $conversionName);
        });
    }

    public function getConversions(string $collectionName = ''): self
    {
        if ($collectionName === '') {
            return $this;
        }

        return $this->filter->shouldBePerformedOn($collectionName);
    }

    /*
     * Get all the conversions in the collection that should be queued.
     */
    public function getQueuedConversions(string $collectionName = ''): self
    {
        return $this->getConversions($collectionName)->filter->shouldBeQueued();
    }

    /*
     * Add the given manipulation to the conversion with the given name.
     */
    protected function addManipulationToConversion(Manipulations $manipulations, string $conversionName)
    {
        optional($this->first(function (Conversion $conversion) use ($conversionName) {
            return $conversion->getName() === $conversionName;
        }))->addAsFirstManipulations($manipulations);

        if ($conversionName === '*') {
            $this->each->addAsFirstManipulations(clone $manipulations);
        }
    }

    /*
     * Get all the conversions in the collection that should not be queued.
     */
    public function getNonQueuedConversions(string $collectionName = ''): self
    {
        return $this->getConversions($collectionName)->reject->shouldBeQueued();
    }

    /*
     * Return the list of conversion files.
     */
    public function getConversionsFiles(string $collectionName = ''): self
    {
        $fileName = pathinfo($this->media->file_name, PATHINFO_FILENAME);

        return $this->getConversions($collectionName)->map(function (Conversion $conversion) use ($fileName) {
            return $conversion->getConversionFile($fileName);
        });
    }
}
