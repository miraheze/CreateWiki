<?php

namespace Miraheze\CreateWiki;

interface IConfigModule {

	public function getErrors(): array;

	public function hasChanges(): bool;

	public function setLogAction( string $action ): void;

	public function getLogAction(): string;

	public function addLogParam( string $param, mixed $value ): void;

	public function getLogParams(): array;

	public function commit(): void;
}
