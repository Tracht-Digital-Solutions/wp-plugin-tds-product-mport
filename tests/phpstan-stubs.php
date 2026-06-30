<?php

const TDS_IMPORTER_VERSION = '1.0.0';
const TDS_IMPORTER_FILE = '';
const TDS_IMPORTER_DIR = '';
const TDS_IMPORTER_URL = '';
const ABSPATH = '';
const ARRAY_A = 'ARRAY_A';
const DAY_IN_SECONDS = 86400;
const HOUR_IN_SECONDS = 3600;

function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 1;
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 1;
}

function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 1;
}

function as_unschedule_all_actions( string $hook, ?array $args = null, string $group = '' ): ?string {
	return null;
}

function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
	return false;
}

