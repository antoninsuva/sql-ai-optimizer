<?php

namespace Soukicz\SqlAiOptimizer;

use Dibi\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AnalyzedDatabase {
    private Connection $connection;
    private string $hostname;
    private int $port;
    public function __construct(
        #[Autowire(env: 'DATABASE_URL')]
        string $databaseUrl
    ) {
        // Parse the DATABASE_URL
        $parsedUrl = parse_url($databaseUrl);
        $this->hostname = $parsedUrl['host'];
        $this->port = $parsedUrl['port'] ?? null;
        $dbConfig = [
            'driver' => 'postgre',
            'host' => $parsedUrl['host'],
            'username' => $parsedUrl['user'],
            'password' => $parsedUrl['pass'],
            'database' => ltrim($parsedUrl['path'], '/'),
            'port' => $parsedUrl['port'] ?? null,
            'charset' => 'utf8',
            'lazy' => true,
        ];

        $this->connection = new Connection($dbConfig);
    }

    public function getHostnameWithPort(): string {
        if ($this->port) {
            return $this->hostname . ':' . $this->port;
        }

        return $this->hostname;
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    public function getQueryText(string $queryid, string $schema): ?string {
        foreach (['pg_stat_statements'] as $table) {
            $sql = $this->connection->query('SELECT query FROM %n WHERE queryid=%s', $table, $queryid)->fetchSingle();
            if ($sql) {
                return $sql;
            }
        }

        return null;
    }

    public function getQueryTexts(array $digests): array {
        $sqls = [];

        foreach (['pg_stat_statements'] as $table) {
            $list = $this->connection->query('SELECT query, queryid, \'public\' as current_schema FROM %n', $table, ' WHERE queryid IN (%s)', $digests)->fetchAll();
            foreach ($list as $item) {
                $sqls[] = [
                    'sql_text' => $item['query'],
                    'queryid' => $item['queryid'],
                    'current_schema' => $item['current_schema'],
                ];
            }
        }

        return $sqls;
    }
}
