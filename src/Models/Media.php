<?php

namespace Spatie\MediaLibrary\Models;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Spatie\MediaLibrary\Helpers\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Support\Htmlable;
use Spatie\MediaLibrary\HasMedia\HasMedia;
use Illuminate\Contracts\Support\Responsable;
use Spatie\MediaLibrary\Conversion\Conversion;
use Spatie\MediaLibrary\Filesystem\Filesystem;
use Spatie\MediaLibrary\Models\Concerns\IsSorted;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\FileAdder\FileAdderFactory;
use Spatie\MediaLibrary\Helpers\TemporaryDirectory;
use Spatie\MediaLibrary\Conversion\ConversionCollection;
use Spatie\MediaLibrary\Events\CollectionHasBeenCleared;
use Spatie\MediaLibrary\ImageGenerators\FileTypes\Image;
use Spatie\MediaLibrary\MediaCollection\MediaCollection;
use Spatie\MediaLibrary\UrlGenerator\UrlGeneratorFactory;
use Spatie\MediaLibrary\Models\Traits\CustomMediaProperties;
use Spatie\MediaLibrary\ResponsiveImages\RegisteredResponsiveImages;

class Media extends Model implements Responsable, Htmlable
{
    use IsSorted,
        CustomMediaProperties;

    const TYPE_OTHER = 'other';

    protected $guarded = [];

    protected $casts = [
        'manipulations' => 'array',
        'custom_properties' => 'array',
        'responsive_images' => 'array',
    ];

    /** @var array */
    public $mediaConversions = [];

    /** @var array */
    public $mediaCollections = [];

    /**
     * Register global media collections.
     *
     * @return void
     */
    public function registerMediaCollections()
    {
        // ...
    }

    /**
     * Add a media collection.
     *
     * @param string $name
     * @return MediaCollection
     */
    public function addMediaCollection(string $name): MediaCollection
    {
        $mediaCollection = MediaCollection::create($name);

        $this->mediaCollections[$name] = $mediaCollection;

        return $mediaCollection;
    }

    /**
     * Clear the media's entire media collection.
     *
     * @param null $model
     * @param \Spatie\MediaLibrary\Models\Media[]|\Illuminate\Support\Collection $excludedMedia
     * @return $this
     */
    public function clearMediaCollection($model = null, $excludedMedia = [])
    {
        if ($model) {
            $query = $model->media();
        } else {
            $query = static::query()->whereNull('model_type')->whereNull('model_id');
        }

        $query->where('collection_name', $this->collection_name);

        $excludedMedia = Collection::wrap($excludedMedia);

        if (! $excludedMedia->isEmpty()) {
            $query->whereNotIn('id', $excludedMedia->pluck('id')->all());
        }

        // Chunk query for performance
        $query->orderBy('id')->chunkById(100, function ($media) {
            $media->each->delete();
        });

        if (optional($model)->relationLoaded('media')) {
            unset($model->media);
        }

        if ($excludedMedia->isEmpty()) {
            event(new CollectionHasBeenCleared($this->collection_name, $model));
        }

        return $this;
    }

    /**
     * Register all media conversions.
     *
     * @return void
     */
    public function registerAllMediaConversions()
    {
        $this->registerMediaCollections();

        collect($this->mediaCollections)->each(function (MediaCollection $mediaCollection) {
            $actualMediaConversions = $this->mediaConversions;

            $this->mediaConversions = [];

            ($mediaCollection->mediaConversionRegistrations)($this);

            $preparedMediaConversions = collect($this->mediaConversions)
                ->each(function (Conversion $conversion) use ($mediaCollection) {
                    $conversion->performOnCollections($mediaCollection->name);
                })
                ->values()
                ->toArray();

            $this->mediaConversions = array_merge($actualMediaConversions, $preparedMediaConversions);
        });

        $this->registerMediaConversions($this);
    }

    /**
     * Register global media conversions.
     *
     * @param  Media|null  $media
     * @return void
     */
    public function registerMediaConversions(self $media = null)
    {
        // ...
    }

    /**
     * Add a conversion.
     *
     * @param string $name
     * @return Conversion
     */
    public function addMediaConversion(string $name): Conversion
    {
        $conversion = Conversion::create($name);

        $this->mediaConversions[$name] = $conversion;

        return $conversion;
    }

    /**
     * The model the media morphs to.
     *
     * @return MorphTo
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine whether the media is associated with a model, or not.
     *
     * @return bool
     */
    public function hasModel()
    {
        return ! (is_null($this->model_type) || is_null($this->model_id));
    }

    /**
     * Add media for the given file.
     *
     * @param string|\Symfony\Component\HttpFoundation\File\UploadedFile $file
     * @return \Spatie\MediaLibrary\FileAdder\FileAdder
     */
    public static function add($file)
    {
        return app(FileAdderFactory::class)->create($file);
    }

    /**
     * Add media from the current request.
     *
     * @param string $key
     * @return \Spatie\MediaLibrary\FileAdder\FileAdder
     */
    public static function addFromRequest($key)
    {
        return app(FileAdderFactory::class)->createFromRequest($key);
    }

    /**
     * Add multiple media from the current request.
     *
     * @param array $keys
     * @return \Spatie\MediaLibrary\FileAdder\FileAdder[]
     */
    public static function addMultipleFromRequest(array $keys)
    {
        return app(FileAdderFactory::class)->createMultipleFromRequest($keys);
    }

    /**
     * Add all media from the current request.
     *
     * @return \Spatie\MediaLibrary\FileAdder\FileAdder[]
     */
    public static function addAllMediaFromRequest()
    {
        return app(FileAdderFactory::class)->createAllFromRequest();
    }

    /*
     * Get the full url to a original media file.
    */
    public function getFullUrl(string $conversionName = ''): string
    {
        return url($this->getUrl($conversionName));
    }

    /*
     * Get the url to a original media file.
     */
    public function getUrl(string $conversionName = ''): string
    {
        $urlGenerator = UrlGeneratorFactory::createForMedia($this, $conversionName);

        return $urlGenerator->getUrl();
    }

    public function getTemporaryUrl(DateTimeInterface $expiration, string $conversionName = '', array $options = []): string
    {
        $urlGenerator = UrlGeneratorFactory::createForMedia($this, $conversionName);

        return $urlGenerator->getTemporaryUrl($expiration, $options);
    }

    /*
     * Get the path to the original media file.
     */
    public function getPath(string $conversionName = ''): string
    {
        $urlGenerator = UrlGeneratorFactory::createForMedia($this, $conversionName);

        return $urlGenerator->getPath();
    }

    public function getImageGenerators(): Collection
    {
        return collect(config('medialibrary.image_generators'));
    }

    public function getTypeAttribute(): string
    {
        $type = $this->getTypeFromExtension();

        if ($type !== self::TYPE_OTHER) {
            return $type;
        }

        return $this->getTypeFromMime();
    }

    public function getTypeFromExtension(): string
    {
        $imageGenerator = $this->getImageGenerators()
            ->map(function (string $className) {
                return app($className);
            })
            ->first->canHandleExtension(strtolower($this->extension));

        return $imageGenerator
            ? $imageGenerator->getType()
            : static::TYPE_OTHER;
    }

    public function getTypeFromMime(): string
    {
        $imageGenerator = $this->getImageGenerators()
            ->map(function (string $className) {
                return app($className);
            })
            ->first->canHandleMime($this->mime_type);

        return $imageGenerator
            ? $imageGenerator->getType()
            : static::TYPE_OTHER;
    }

    public function getExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    public function getHumanReadableSizeAttribute(): string
    {
        return File::getHumanReadableSize($this->size);
    }

    public function getDiskDriverName(): string
    {
        return strtolower(config("filesystems.disks.{$this->disk}.driver"));
    }

    /*
     * Determine if the media item has a custom property with the given name.
     */
    public function hasCustomProperty(string $propertyName): bool
    {
        return array_has($this->custom_properties, $propertyName);
    }

    /**
     * Get the value of custom property with the given name.
     *
     * @param string $propertyName
     * @param mixed $default
     *
     * @return mixed
     */
    public function getCustomProperty(string $propertyName, $default = null)
    {
        return array_get($this->custom_properties, $propertyName, $default);
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @return $this
     */
    public function setCustomProperty(string $name, $value): self
    {
        $customProperties = $this->custom_properties;

        array_set($customProperties, $name, $value);

        $this->custom_properties = $customProperties;

        return $this;
    }

    public function forgetCustomProperty(string $name): self
    {
        $customProperties = $this->custom_properties;

        array_forget($customProperties, $name);

        $this->custom_properties = $customProperties;

        return $this;
    }

    /*
     * Get all the names of the registered media conversions.
     */
    public function getMediaConversionNames(): array
    {
        return ConversionCollection::createForMedia($this)->keys()->all();
    }

    public function hasGeneratedConversion(string $conversionName): bool
    {
        $generatedConversions = $this->getGeneratedConversions();

        return $generatedConversions[$conversionName] ?? false;
    }

    public function markAsConversionGenerated(string $conversionName, bool $generated): self
    {
        $this->setCustomProperty("generated_conversions.{$conversionName}", $generated);

        $this->save();

        return $this;
    }

    public function getGeneratedConversions(): Collection
    {
        return collect($this->getCustomProperty('generated_conversions', []));
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function toResponse($request)
    {
        $downloadHeaders = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-Type' => $this->mime_type,
            'Content-Length' => $this->size,
            'Content-Disposition' => 'attachment; filename="'.$this->file_name.'"',
            'Pragma' => 'public',
        ];

        return response()->stream(function () {
            $stream = $this->stream();

            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $downloadHeaders);
    }

    public function getResponsiveImageUrls(string $conversionName = ''): array
    {
        return $this->responsiveImages($conversionName)->getUrls();
    }

    public function hasResponsiveImages(string $conversionName = ''): bool
    {
        return count($this->getResponsiveImageUrls($conversionName)) > 0;
    }

    public function getSrcset(string $conversionName = ''): string
    {
        return $this->responsiveImages($conversionName)->getSrcset();
    }

    public function toHtml()
    {
        return $this->img();
    }

    /**
     * @param string|array $conversion
     * @param array $extraAttributes
     *
     * @return string
     */
    public function img($conversion = '', array $extraAttributes = []): string
    {
        if (! (new Image())->canHandleMime($this->mime_type)) {
            return '';
        }

        if (is_array($conversion)) {
            $attributes = $conversion;

            $conversion = $attributes['conversion'] ?? '';

            unset($attributes['conversion']);

            $extraAttributes = array_merge($attributes, $extraAttributes);
        }

        $attributeString = collect($extraAttributes)
            ->map(function ($value, $name) {
                return $name.'="'.$value.'"';
            })->implode(' ');

        if (strlen($attributeString)) {
            $attributeString = ' '.$attributeString;
        }

        $media = $this;

        $viewName = 'image';

        $width = '';

        if ($this->hasResponsiveImages($conversion)) {
            $viewName = config('medialibrary.responsive_images.use_tiny_placeholders')
                ? 'responsiveImageWithPlaceholder'
                : 'responsiveImage';

            $width = $this->responsiveImages($conversion)->files->first()->width();
        }

        return view("medialibrary::{$viewName}", compact(
            'media',
            'conversion',
            'attributeString',
            'width'
        ));
    }

    public function move(HasMedia $model, $collectionName = 'default'): self
    {
        $newMedia = $this->copy($model, $collectionName);

        $this->delete();

        return $newMedia;
    }

    public function copy(HasMedia $model, $collectionName = 'default'): self
    {
        $temporaryDirectory = TemporaryDirectory::create();

        $temporaryFile = $temporaryDirectory->path($this->file_name);

        app(Filesystem::class)->copyFromMediaLibrary($this, $temporaryFile);

        $newMedia = $model
            ->addMedia($temporaryFile)
            ->usingName($this->name)
            ->withCustomProperties($this->custom_properties)
            ->toMediaCollection($collectionName);

        $temporaryDirectory->delete();

        return $newMedia;
    }

    public function responsiveImages(string $conversionName = ''): RegisteredResponsiveImages
    {
        return new RegisteredResponsiveImages($this, $conversionName);
    }

    public function stream()
    {
        $filesystem = app(Filesystem::class);

        return $filesystem->getStream($this);
    }

    public function __invoke(...$arguments)
    {
        return new HtmlString($this->img(...$arguments));
    }
}
