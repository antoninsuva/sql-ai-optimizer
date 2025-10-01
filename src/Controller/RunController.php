<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use Soukicz\SqlAiOptimizer\QuerySelector;
use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use ZipArchive;

class RunController extends BaseController {
    public function __construct(
        private AnalyzedDatabase $analyzedDatabase,
        private QuerySelector $querySelector,
        private Environment $twig,
        private StateDatabase $stateDatabase,
        private UrlGeneratorInterface $router,
        private AnalysisController $analysisController
    ) {
    }

    #[Route('/run/{id}', name: 'run.detail')]
    public function runDetail(int $id, Request $request): Response {
        $run = $this->stateDatabase->getRun($id);

        if (!$run) {
            return new RedirectResponse($this->router->generate('index'));
        }

        $groups = $this->stateDatabase->getGroupsByRunId($id);
        $queries = $this->stateDatabase->getQueriesByRunId($id);
        $queries = array_map(function ($query) {
            $query = (array)$query;
            $query['normalized_query_formatted'] = Helpers::dump($query['normalized_query'], true);
            $query['normalized_query_formatted'] = preg_replace('/^<pre[^>]*>|<\/pre>$/', '', $query['normalized_query_formatted']);

            return $query;
        }, $queries);

        $missingSqlCount = 0;
        foreach ($queries as $query) {
            if (empty($query['real_query'])) {
                $missingSqlCount++;
            }
        }

        $specialInstructions = $run['input'];
        if (!empty($specialInstructions)) {
            $specialInstructions = nl2br(htmlspecialchars($specialInstructions));
        }

        $isExport = $request->query->has('export');
        $templateVars = [
            'summary' => $this->renderMarkdownWithHighlighting($run['output']),
            'run' => $run,
            'groups' => $groups,
            'queries' => $queries,
            'missingSqlCount' => $missingSqlCount,
            'specialInstructions' => $specialInstructions,
        ];

        if ($isExport) {
            if ($request->query->get('format') === 'zip') {
                return $this->exportAsZip($id, $run, $groups, $queries, $templateVars);
            }

            $templateVars['export'] = true;
            $content = $this->twig->render('run_detail.html.twig', $templateVars);

            $response = new Response($content);
            $response->headers->set('Content-Type', 'text/html');
            $response->headers->set('Content-Disposition', 'attachment; filename="run-' . $id . '-export.html"');

            return $response;
        }

        return new Response(
            $this->twig->render('run_detail.html.twig', $templateVars)
        );
    }

    /**
     * Export run details and all queries as a ZIP file
     */
    private function exportAsZip(int $id, array $run, array $groups, array $queries, array $templateVars): Response {
        $tempDir = sys_get_temp_dir() . '/sql-optimizer-export-' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        // Add export flag and ZIP export specific flag
        $templateVars['export'] = true;
        $templateVars['zip_export'] = true;

        // Render main run detail page
        $content = $this->twig->render('run_detail.html.twig', $templateVars);
        file_put_contents($tempDir . '/index.html', $content);

        // Collect queries with analyzed data
        $analyzedQueries = [];
        foreach ($queries as $query) {
            if (!empty($query['llm_conversation'])) {
                $analyzedQueries[] = $query;
            }
        }

        // Create a mock request for use with AnalysisController
        $request = new Request();
        $request->query->set('export', '1');
        $request->query->set('zip_export', '1');

        // Export each analyzed query using AnalysisController
        foreach ($analyzedQueries as $query) {
            // Get query content using the AnalysisController
            $response = $this->analysisController->queryDetail($query['id'], $request);

            // Modify the content to use local URLs for the ZIP file
            $queryContent = $response->getContent();

            // Replace the backToRunUrl with local reference
            $queryContent = str_replace(
                'href="' . $this->router->generate('run.detail', ['id' => $query['run_id']]) . '"',
                'href="index.html"',
                $queryContent
            );

            // Save to file
            file_put_contents($tempDir . '/query' . $query['id'] . '.html', $queryContent);
        }

        // Create ZIP file
        $zipFile = $tempDir . '/export.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            throw new \Exception("Cannot create ZIP file");
        }

        // Add all files to ZIP
        $dir = new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($dir);
        foreach ($iterator as $file) {
            // Skip the ZIP file itself
            if ($file->getPathname() === $zipFile) {
                continue;
            }

            // Add file to ZIP with path relative to temp directory
            $relativePath = substr($file->getPathname(), strlen($tempDir) + 1);
            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->close();

        // Create response with ZIP file
        $response = new Response(file_get_contents($zipFile));
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="run-' . $id . '-export.zip"');

        // Clean up temporary files (optional)
        $this->removeDirectory($tempDir);

        return $response;
    }

    /**
     * Recursively remove a directory and its contents
     */
    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }

            $path = $dir . '/' . $object;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    #[Route('/test-db', name: 'connection.test', methods: ['GET'])]
    public function testDatabaseConnection(): Response
    {
        try {
            $connection = $this->analyzedDatabase->getConnection();
            $now = $connection->query('SELECT NOW()')->fetchSingle();
            $version = $connection->query('SELECT version()')->fetchSingle();

            return new JsonResponse([
                'status'   => 'ok',
                'database' => [
                    'db_name' => $connection->getConfig('database'),
                    'version' => $version,
                    'time'    => $now,
                ]
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/new-run', name: 'run.new', methods: ['POST'])]
    public function newRun(Request $request): Response {
        $results = $this->querySelector->getCandidateQueries($request->request->get('input'));

        $useRealQuery = $request->request->getBoolean('use_real_query', false);

        $this->stateDatabase->getConnection()->begin();
        $runId = $this->stateDatabase->createRun(
            $request->request->get('input'),
            $this->analyzedDatabase->getHostnameWithPort(),
            $results->getDescription(),
            $useRealQuery,
            $request->request->getBoolean('use_database_access', false),
            $results->getConversation(),
            $results->getFormattedConversation()
        );

        foreach ($results->getGroups() as $group) {
            $groupId = $this->stateDatabase->createGroup($runId, $group->getName(), $group->getDescription());

            foreach ($group->getQueries() as $query) {
                if (empty($query->getSchema()) || $query->getSchema() === 'NULL' || $query->getSchema() === 'unknown') {
                    continue;
                }

                $rawSql = $this->analyzedDatabase->getQueryText($query->getQueryid(), $query->getSchema());

                $this->stateDatabase->createQuery(
                    runId: $runId,
                    groupId: $groupId,
                    queryid: $query->getQueryid(),
                    normalizedQuery: $query->getNormalizedQuery(),
                    realQuery: $rawSql,
                    schema: $query->getSchema(),
                    impactDescription: $query->getImpactDescription()
                );
            }
        }

        $this->stateDatabase->getConnection()->commit();

        return new JsonResponse([
            'url' => '/run/' . $runId . '#first',
        ]);
    }

    #[Route('/run/{id}/fetch-queries', name: 'run.fetch-queries')]
    public function runFetchQueries(int $id): Response {
        $run = $this->stateDatabase->getRun($id);
        if (!$run) {
            return new JsonResponse([
                'error' => 'Run not found',
            ], 404);
        }

        $queryids = [];
        $queries = [];
        $totalQueriesCount = $this->stateDatabase->getQueriesCount($id);
        foreach ($this->stateDatabase->getQueriesWithoutRealQuery($id) as $query) {
            if (!isset($queryids[$query['queryid']])) {
                $queryids[$query['queryid']] = [];
            }

            $queryids[$query['queryid']][] = $query['id'];
            $queries[$query['id']] = $query['schema'];
        }

        if (!empty($queryids)) {
            foreach ($this->analyzedDatabase->getQueryTexts(array_keys($queryids)) as $sql) {
                if (isset($queryids[$sql['queryid']])) {
                    foreach ($queryids[$sql['queryid']] as $i => $id) {
                        if ($queries[$id] === $sql['current_schema']) {
                            $this->stateDatabase->setRealQuery($id, $sql['sql_text']);
                            unset($queries[$id]);
                            unset($queryids[$sql['queryid']][$i]);
                        }
                    }
                }
            }
        }

        return new JsonResponse([
            'totalQueriesCount' => $totalQueriesCount,
            'missingQueriesCount' => count($queries),
        ]);
    }
}
