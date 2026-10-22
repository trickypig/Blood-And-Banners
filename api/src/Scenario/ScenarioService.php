<?php
declare(strict_types=1);

namespace BloodAndBanners\Scenario;

use DomainException;
use PDO;
use Throwable;

final class ScenarioService
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $ownerUserId, array $definition): array
    {
        $this->validate($definition);
        $this->connection->beginTransaction();
        try {
            $scenarioId = self::id();
            $revisionId = self::id();
            $now = self::now();
            $this->connection->prepare(
                'INSERT INTO scenarios (id, owner_user_id, name, visibility, created_at)
                 VALUES (:id, :owner, :name, :visibility, :created)'
            )->execute([
                'id' => $scenarioId,
                'owner' => $ownerUserId,
                'name' => $definition['name'],
                'visibility' => 'private',
                'created' => $now,
            ]);
            $this->insertRevision($revisionId, $scenarioId, 1, $definition, $now);
            $this->connection->commit();
            return ['scenarioId' => $scenarioId, 'revisionId' => $revisionId, 'revision' => 1];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function addRevision(string $scenarioId, string $ownerUserId, array $definition): array
    {
        $this->validate($definition);
        $this->connection->beginTransaction();
        try {
            // Serialize all revision writers on the parent, including SQLite before any read.
            $this->connection->prepare('UPDATE scenarios SET name = name WHERE id = :id AND owner_user_id = :owner')
                ->execute(['id' => $scenarioId, 'owner' => $ownerUserId]);
            $query = $this->connection->prepare('SELECT owner_user_id FROM scenarios WHERE id = :id AND deleted_at IS NULL');
            $query->execute(['id' => $scenarioId]);
            $owner = $query->fetchColumn();
            if ($owner === false || $owner !== $ownerUserId) {
                throw new DomainException('scenarioOwnerRequired');
            }
            $revision = (int)$this->scalar(
                'SELECT COALESCE(MAX(revision), 0) + 1 FROM scenario_revisions WHERE scenario_id = :scenario',
                ['scenario' => $scenarioId]
            );
            $revisionId = self::id();
            $now = self::now();
            $this->insertRevision($revisionId, $scenarioId, $revision, $definition, $now);
            $this->connection->prepare('UPDATE scenarios SET name = :name WHERE id = :id')
                ->execute(['name' => $definition['name'], 'id' => $scenarioId]);
            $this->connection->commit();
            return ['scenarioId' => $scenarioId, 'revisionId' => $revisionId, 'revision' => $revision];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function getRevision(string $revisionId): array
    {
        $statement = $this->connection->prepare('SELECT definition_json FROM scenario_revisions WHERE id = :id');
        $statement->execute(['id' => $revisionId]);
        $json = $statement->fetchColumn();
        if ($json === false) {
            throw new DomainException('scenarioRevisionNotFound');
        }
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function listOwned(string $ownerUserId): array
    {
        $query = $this->connection->prepare($this->ownedQuery(false) . ' ORDER BY r.created_at DESC, s.id DESC');
        $query->execute(['owner' => $ownerUserId]);
        return ['maps' => array_map(fn(array $row): array => $this->summary($row), $query->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function loadOwned(string $scenarioId, string $ownerUserId): array
    {
        $query = $this->connection->prepare($this->ownedQuery() . ' AND s.id = :id');
        $query->execute(['owner' => $ownerUserId, 'id' => $scenarioId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new MapException(404, 'mapNotFound', 'The map could not be found.');
        }
        return ['summary' => $this->summary($row), 'definition' => MapValidator::structure(json_decode($row['definition_json'], false, flags: JSON_THROW_ON_ERROR))];
    }

    public function createOwned(string $ownerUserId, mixed $definition): array
    {
        $definition = MapValidator::validate($definition);
        $created = $this->create($ownerUserId, $definition);
        return $this->loadOwned($created['scenarioId'], $ownerUserId);
    }

    public function saveOwned(string $scenarioId, string $ownerUserId, string $expectedRevisionId, mixed $definition): array
    {
        // Check ownership before validating to give missing and other-owned IDs identical responses.
        $this->loadOwned($scenarioId, $ownerUserId);
        $definition = MapValidator::validate($definition);
        for ($attempt = 0; ; $attempt++) {
            $this->connection->beginTransaction();
            try {
                $lock = $this->connection->prepare('UPDATE scenarios SET name = name WHERE id = :id AND owner_user_id = :owner AND deleted_at IS NULL');
                $lock->execute(['id' => $scenarioId, 'owner' => $ownerUserId]);
                $current = $this->loadOwned($scenarioId, $ownerUserId);
                if ($current['summary']['revisionId'] !== $expectedRevisionId) {
                    throw new MapException(409, 'mapConflict', 'This map was saved elsewhere. Reload it or save your edits as a new map.');
                }
                $revision = $current['summary']['revision'] + 1;
                $this->insertRevision(self::id(), $scenarioId, $revision, $definition, self::now());
                $this->connection->prepare('UPDATE scenarios SET name = :name WHERE id = :id')
                    ->execute(['id' => $scenarioId, 'name' => $definition['name']]);
                $saved = $this->loadOwned($scenarioId, $ownerUserId);
                $this->connection->commit();
                return $saved;
            } catch (\PDOException $error) {
                $this->rollback();
                $driverCode = (int)($error->errorInfo[1] ?? 0);
                if (in_array($driverCode, [5, 6, 19, 1062, 1205, 1213], true)) {
                    if ($attempt < 2) { usleep(20000); continue; }
                    throw new MapException(409, 'mapConflict', 'The map is being saved elsewhere. Try again or save as a new map.');
                }
                throw $error;
            } catch (Throwable $error) {
                $this->rollback();
                throw $error;
            }
        }
    }

    private function ownedQuery(bool $includeDefinition = true): string
    {
        return 'SELECT s.id, s.name, r.id AS revision_id, r.revision, r.created_at' . ($includeDefinition ? ', r.definition_json' : '') . '
                FROM scenarios s JOIN scenario_revisions r ON r.scenario_id = s.id
                WHERE s.owner_user_id = :owner AND s.deleted_at IS NULL
                AND r.revision = (SELECT MAX(latest.revision) FROM scenario_revisions latest WHERE latest.scenario_id = s.id)';
    }

    private function summary(array $row): array
    {
        return ['scenarioId' => $row['id'], 'name' => $row['name'], 'revisionId' => $row['revision_id'],
            'revision' => (int)$row['revision'], 'updatedAt' => str_replace(' ', 'T', $row['created_at']) . 'Z'];
    }

    public function builtInDefinition(string $name): array
    {
        $files = glob(dirname(__DIR__, 2) . '/scenarios/*.json') ?: [];
        foreach ($files as $file) {
            $json = file_get_contents($file);
            if ($json === false) {
                continue;
            }
            $definition = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (($definition['name'] ?? null) !== $name) {
                continue;
            }
            $this->validate($definition);
            return $definition;
        }

        throw new DomainException('scenarioNotFound');
    }

    public function resolveBuiltInRevision(string $name, string $mode): array
    {
        $definition = $this->builtInDefinition($name);
        if (!in_array($mode, $definition['modeCompatibility'], true)) {
            throw new DomainException('scenarioModeIncompatible');
        }

        $scenarioId = substr(hash('sha256', "built-in-scenario:{$name}"), 0, 32);
        $definitionJson = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $revisionId = substr(hash('sha256', "{$scenarioId}:{$definitionJson}"), 0, 32);
        $now = self::now();
        $scenarioInsert = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT IGNORE INTO scenarios (id, owner_user_id, name, visibility, created_at) VALUES (:id, NULL, :name, :visibility, :created)'
            : 'INSERT OR IGNORE INTO scenarios (id, owner_user_id, name, visibility, created_at) VALUES (:id, NULL, :name, :visibility, :created)';
        $this->connection->prepare($scenarioInsert)->execute([
            'id' => $scenarioId,
            'name' => $name,
            'visibility' => 'builtin',
            'created' => $now,
        ]);

        $existing = (int)$this->scalar(
            'SELECT COUNT(*) FROM scenario_revisions WHERE id = :id',
            ['id' => $revisionId]
        );
        if ($existing === 0) {
            $revision = (int)$this->scalar(
                'SELECT COALESCE(MAX(revision), 0) + 1 FROM scenario_revisions WHERE scenario_id = :scenario',
                ['scenario' => $scenarioId]
            );
            $this->insertRevision($revisionId, $scenarioId, $revision, $definition, $now);
        }

        return ['revisionId' => $revisionId, 'definition' => $definition];
    }

    private function validate(array $definition): void
    {
        if (($definition['schemaVersion'] ?? null) !== 1
            || trim((string)($definition['name'] ?? '')) === ''
            || !is_array($definition['modeCompatibility'] ?? null)
            || ($definition['modeCompatibility'] ?? []) === []
            || !is_array($definition['map']['hexes'] ?? null)) {
            throw new DomainException('invalidScenarioDefinition');
        }
        $invalidModes = array_diff($definition['modeCompatibility'], ['vanillaPvp', 'coop', 'battleRoyale']);
        if ($invalidModes !== []) {
            throw new DomainException('invalidScenarioMode');
        }
    }

    private function insertRevision(string $id, string $scenarioId, int $revision, array $definition, string $createdAt): void
    {
        $this->connection->prepare(
            'INSERT INTO scenario_revisions (id, scenario_id, revision, definition_json, created_at)
             VALUES (:id, :scenario, :revision, :definition, :created)'
        )->execute([
            'id' => $id,
            'scenario' => $scenarioId,
            'revision' => $revision,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
            'created' => $createdAt,
        ]);
    }

    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    private function rollback(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    private static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
