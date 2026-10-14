<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $connection;
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \LogicException('Brak bazy testowej.');
        } $this->connection = $connection;
        $connection->beginTransaction();
    }
    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        } parent::tearDown();
    }
    protected function db(): Connection
    {
        return $this->connection;
    }
}
