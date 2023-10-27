<?php

namespace Rakutentech\LaravelRequestDocs\Commands;

use ErrorException;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Rakutentech\LaravelRequestDocs\LaravelRequestDocs;
use Rakutentech\LaravelRequestDocs\LaravelRequestDocsToOpenApi;

class ExportRequestDocsCommand extends Command
{
    private LaravelRequestDocs          $laravelRequestDocs;
    private LaravelRequestDocsToOpenApi $laravelRequestDocsToOpenApi;

    public function __construct(LaravelRequestDocs $laravelRequestDoc, LaravelRequestDocsToOpenApi $laravelRequestDocsToOpenApi)
    {
        parent::__construct();

        $this->laravelRequestDocs          = $laravelRequestDoc;
        $this->laravelRequestDocsToOpenApi = $laravelRequestDocsToOpenApi;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravel-request-docs:export
                            {path? : Export file location}
                            {--sort=default : Sort the data by route names}
                            {--groupby=default : Group the data by API URI}
                            {--force : Whether to overwrite existing file}';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate OpenAPI collection as json file';

    private string $exportFilePath;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->confirmFilePathAvailability()) {
            //silently stop command
            return self::SUCCESS;
        }

        try {
            //get the excluded methods list from config
            $excludedMethods = config('request-docs.open_api.exclude_http_methods', []);
            $excludedMethods = array_map(fn($item) => strtolower($item), $excludedMethods);

            //filter while method apis to export
            $showGet    = !in_array('get', $excludedMethods);
            $showPost   = !in_array('post', $excludedMethods);
            $showPut    = !in_array('put', $excludedMethods);
            $showPatch  = !in_array('patch', $excludedMethods);
            $showDelete = !in_array('delete', $excludedMethods);
            $showHead   = !in_array('head', $excludedMethods);

            // Get a list of Doc with route and rules information.
            $docs = $this->laravelRequestDocs->getDocs(
                $showGet,
                $showPost,
                $showPut,
                $showPatch,
                $showDelete,
                $showHead,
            );

            // Loop and split Doc by the `methods` property.
            $docs = $this->laravelRequestDocs->splitByMethods($docs);
            $docs = $this->laravelRequestDocs->sortDocs($docs, $this->option('sort'));
            $docs = $this->laravelRequestDocs->groupDocs($docs, $this->option('groupby'));

            if (!$this->writeApiDocsToFile($docs)) {
                throw new ErrorException("Failed to write on [{$this->exportFilePath}] file.");
            }
        } catch (Exception $exception) {
            $this->error('Error : ' . $exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return bool
     */
    private function confirmFilePathAvailability(): bool
    {
        $path = $this->argument('path');

        if (!$path) {
            $path = config('request-docs.export_path', 'api.json');
        }

        $this->exportFilePath = base_path($path);

        $path = str_replace(base_path('/'), '', $this->exportFilePath);

        if (file_exists($this->exportFilePath)) {
            if (!$this->option('force')) {
                if ($this->confirm("File exists on [{$path}]. Overwrite?", false) == true) {
                    return true;
                }
                return false;
            }
        }

        return true;
    }

    /**
     * @param $docs
     * @return bool
     */
    private function writeApiDocsToFile(Collection $docs): bool
    {
        $content = json_encode(
            $this->laravelRequestDocsToOpenApi->openApi($docs->all())->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $targetDirectory = dirname($this->exportFilePath);

        //create parent directory if not exists
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        return file_put_contents($this->exportFilePath, $content) !== false;
    }
}
