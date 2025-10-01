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

    public function getQueryText(string $digest, string $schema): ?string {
        foreach (['events_statements_history', 'events_statements_history_long'] as $table) {
            $sql = $this->connection->query('SELECT sql_text FROM performance_schema.%n WHERE digest=%s', $table, $digest, ' AND current_schema = %s', $schema)->fetchSingle();
            if ($sql) {
                return $sql;
            }
        }

        return null;
    }

    public function getQueryTexts(array $digests): array {
        $sqls = [];

        foreach (['events_statements_history', 'events_statements_history_long'] as $table) {
            $list = $this->connection->query('SELECT sql_text,digest,current_schema FROM performance_schema.%n', $table, ' WHERE digest IN (%s)', $digests)->fetchAll();
            foreach ($list as $item) {
                $sqls[] = [
                    'sql_text' => $item['sql_text'],
                    'digest' => $item['digest'],
                    'current_schema' => $item['current_schema'],
                ];
            }
        }

        return $sqls;
    }
}
