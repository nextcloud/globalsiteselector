<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0110Date20180925143400 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 * @since 13.0.0
	 */
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('global_scale_users')) {
			$table = $schema->createTable('global_scale_users');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('uid', Types::STRING, [
				'autoincrement' => false,
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('displayname', Types::STRING, [
				'notnull' => false,
				'length' => 255,
				'default' => '',
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['uid'], 'gss_uid_index');
		}

		return $schema;
	}
}
