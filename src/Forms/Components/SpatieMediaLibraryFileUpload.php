<?php

namespace Filament\Forms\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use Livewire\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SpatieMediaLibraryFileUpload extends FileUpload
{
    protected string | Closure | null $collection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(function (SpatieMediaLibraryFileUpload $component, ?HasMedia $record): void {
            if (! $record) {
                $component->state([]);

                return;
            }

            $files = $record->getMedia($component->getCollection())
                ->when(
                    ! $component->isMultiple(),
                    fn (Collection $files): Collection => $files->take(1),
                )
                ->mapWithKeys(function (Media $file): array {
                    $uuid = $file->getAttributeValue('uuid');

                    return [$uuid => $uuid];
                })
                ->toArray();

            $component->state($files);
        });

        $this->beforeStateDehydrated(null);

        $this->dehydrated(false);

        $this->getUploadedFileUrlUsing(function (SpatieMediaLibraryFileUpload $component, string $file): ?string {
            /** @var FilesystemAdapter $storage */
            $storage = $component->getDisk();

            /** @var \League\Flysystem\Filesystem $storageDriver */
            $storageDriver = $storage->getDriver();

            if (! $component->getRecord()) {
                return null;
            }

            /** @var ?Media $media */
            $media = Media::findByUuid($file);

            if (
                $storageDriver->getAdapter() instanceof AwsS3Adapter &&
                $this->getVisibility() === 'private'
            ) {
                return $media?->getTemporaryUrl(now()->addMinutes(5));
            }

            return $media?->getUrl();
        });

        $this->saveRelationshipsUsing(function (SpatieMediaLibraryFileUpload $component) {
            $component->saveUploadedFiles();
        });

        $this->saveUploadedFileUsing(function (SpatieMediaLibraryFileUpload $component, TemporaryUploadedFile $file, ?Model $record): string {
            if (! method_exists($record, 'addMediaFromString')) {
                return $file;
            }

            /** @var FileAdder $mediaAdder */
            $mediaAdder = $record->addMediaFromString($file->get());

            $filename = $component->shouldPreserveFilenames() ? $file->getClientOriginalName() : $file->getFilename();

            $media = $mediaAdder
                ->usingFileName($filename)
                ->toMediaCollection($component->getCollection(), $component->getDiskName());

            return $media->getAttributeValue('uuid');
        });

        $this->deleteUploadedFileUsing(function (SpatieMediaLibraryFileUpload $component, string $file): void {
            if (! $file) {
                return;
            }

            Media::findByUuid($file)?->delete();
        });

        $this->reorderUploadedFilesUsing(function (SpatieMediaLibraryFileUpload $component, array $state): array {
            Media::setNewOrder(collect($state)->values()->map(fn ($file) => Media::findByUuid($file))->filter()->pluck('id')->toArray());

            return $state;
        });
    }

    public function collection(string | Closure | null $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function getCollection(): string
    {
        return $this->evaluate($this->collection) ?? 'default';
    }
}
