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
