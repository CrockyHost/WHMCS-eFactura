<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use WHMCS\Module\Addon\Efactura\Database\Migration;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return new class implements Migration {
    private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(ConnectionInterface $db): void
    {
        // Settings that belong to the addon. They live outside tbladdonmodules
        // so that deactivating the module does not wipe them.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_settings` (
            `name` VARCHAR(64) NOT NULL,
            `value` MEDIUMTEXT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`name`)
        ) ' . self::OPTIONS);

        // One row per fiscal document sent (or to be sent) to SPV: an invoice,
        // or a storno of an invoice. `xml` holds the exact bytes uploaded, so
        // retries resend the same document.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_documents` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dedupe_key` VARCHAR(64) NOT NULL,
            `kind` VARCHAR(16) NOT NULL,
            `reason` VARCHAR(24) NULL,
            `invoice_id` INT UNSIGNED NOT NULL,
            `billing_note_id` INT UNSIGNED NULL,
            `original_document_id` BIGINT UNSIGNED NULL,
            `client_id` INT UNSIGNED NOT NULL,
            `number` VARCHAR(64) NULL,
            `issue_date` DATE NULL,
            `currency` CHAR(3) NULL,
            `total` DECIMAL(16,2) NULL,
            `buyer_type` VARCHAR(8) NULL,
            `state` VARCHAR(24) NOT NULL,
            `state_changed_at` DATETIME NULL,
            `exclusion_reason` VARCHAR(48) NULL,
            `send_after` DATETIME NULL,
            `deadline_date` DATE NULL,
            `held_at` DATETIME NULL,
            `held_by` INT UNSIGNED NULL,
            `environment` VARCHAR(8) NULL,
            `xml` MEDIUMBLOB NULL,
            `xml_sha256` CHAR(64) NULL,
            `xml_generated_at` DATETIME NULL,
            `upload_endpoint` VARCHAR(16) NULL,
            `upload_started_at` DATETIME NULL,
            `upload_index` VARCHAR(32) NULL,
            `uploaded_at` DATETIME NULL,
            `anaf_state` VARCHAR(64) NULL,
            `download_id` VARCHAR(32) NULL,
            `validated_at` DATETIME NULL,
            `errors` MEDIUMTEXT NULL,
            `last_error` TEXT NULL,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at` DATETIME NULL,
            `status_checks` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `downloads` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `counters_date` DATE NULL,
            `lock_token` VARCHAR(40) NULL,
            `locked_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `dedupe_key` (`dedupe_key`),
            UNIQUE KEY `number` (`number`),
            KEY `invoice_id` (`invoice_id`),
            KEY `billing_note_id` (`billing_note_id`),
            KEY `original_document_id` (`original_document_id`),
            KEY `client_id` (`client_id`),
            KEY `state_send_after` (`state`, `send_after`),
            KEY `next_attempt_at` (`next_attempt_at`),
            KEY `upload_index` (`upload_index`)
        ) ' . self::OPTIONS);

        // Files kept for the legal retention period: the XML sent, the signed
        // ZIP from ANAF (downloadable there for 60 days only), PDFs, and the
        // files of received invoices from the SPV inbox.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_archive` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `document_id` BIGINT UNSIGNED NULL,
            `message_id` BIGINT UNSIGNED NULL,
            `kind` VARCHAR(24) NOT NULL,
            `environment` VARCHAR(8) NOT NULL,
            `upload_index` VARCHAR(32) NULL,
            `download_id` VARCHAR(32) NULL,
            `filename` VARCHAR(191) NOT NULL,
            `mime` VARCHAR(64) NOT NULL,
            `size` INT UNSIGNED NOT NULL,
            `sha256` CHAR(64) NOT NULL,
            `content` LONGBLOB NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `document_id` (`document_id`),
            KEY `message_id` (`message_id`),
            KEY `download_id` (`download_id`)
        ) ' . self::OPTIONS);

        // OAuth credentials and tokens per ANAF environment. Secret columns
        // hold values encrypted with WHMCS encrypt().
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_oauth` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `environment` VARCHAR(8) NOT NULL,
            `client_id` TEXT NULL,
            `client_secret` TEXT NULL,
            `access_token` TEXT NULL,
            `refresh_token` TEXT NULL,
            `access_expires_at` DATETIME NULL,
            `refresh_expires_at` DATETIME NULL,
            `authorized_at` DATETIME NULL,
            `authorized_by` INT UNSIGNED NULL,
            `refreshed_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `environment` (`environment`)
        ) ' . self::OPTIONS);

        // SPV inbox: messages from listaMesaje (received invoices, buyer
        // messages, errors).
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `environment` VARCHAR(8) NOT NULL,
            `anaf_id` VARCHAR(32) NOT NULL,
            `request_id` VARCHAR(32) NULL,
            `cif` VARCHAR(32) NULL,
            `type` VARCHAR(64) NOT NULL,
            `details` TEXT NULL,
            `anaf_created_at` DATETIME NULL,
            `issuer_cif` VARCHAR(32) NULL,
            `issuer_name` VARCHAR(255) NULL,
            `invoice_number` VARCHAR(64) NULL,
            `invoice_date` DATE NULL,
            `currency` CHAR(3) NULL,
            `total` DECIMAL(16,2) NULL,
            `archive_id` BIGINT UNSIGNED NULL,
            `seen_at` DATETIME NULL,
            `seen_by` INT UNSIGNED NULL,
            `processed_at` DATETIME NULL,
            `processed_by` INT UNSIGNED NULL,
            `raw` MEDIUMTEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `environment_anaf_id` (`environment`, `anaf_id`),
            KEY `type` (`type`),
            KEY `anaf_created_at` (`anaf_created_at`)
        ) ' . self::OPTIONS);

        // Every state transition and admin action, for audit and support.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_audit` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `document_id` BIGINT UNSIGNED NULL,
            `invoice_id` INT UNSIGNED NULL,
            `event` VARCHAR(48) NOT NULL,
            `from_state` VARCHAR(24) NULL,
            `to_state` VARCHAR(24) NULL,
            `message` TEXT NULL,
            `context` MEDIUMTEXT NULL,
            `admin_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `document_id` (`document_id`),
            KEY `invoice_id` (`invoice_id`),
            KEY `event` (`event`),
            KEY `created_at` (`created_at`)
        ) ' . self::OPTIONS);
    }
};
