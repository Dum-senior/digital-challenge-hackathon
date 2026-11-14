<?php
/**
 * 096 — One private, versioned, human-reviewed AI intake draft per submission.
 * No production ticket, SLA or notifications are created by this migration.
 * Safe for repeated runs; existing ticket_drafts autosave is unchanged.
 */
return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_intake_drafts` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `user_id` int(10) unsigned NOT NULL,
      `source` enum('text','voice','phone') NOT NULL DEFAULT 'text',
      `request_key` char(64) DEFAULT NULL,
      `request_fingerprint` char(64) NOT NULL,
      `raw_text` text NOT NULL,
      `analysis_json` mediumtext NOT NULL,
      `original_analysis_json` mediumtext NOT NULL,
      `edited_json` mediumtext NOT NULL,
      `conversation_json` mediumtext NOT NULL,
      `status` enum('needs_clarification','awaiting_review','failed','created') NOT NULL,
      `revision` int(10) unsigned NOT NULL DEFAULT 1,
      `confirmed_ticket_id` int(10) unsigned DEFAULT NULL,
      `confirmed_by` int(10) unsigned DEFAULT NULL,
      `postprocess_state` enum('pending','done','failed') NOT NULL DEFAULT 'pending',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_ai_intake_user_request` (`user_id`,`request_key`),
      KEY `idx_ai_intake_status_updated` (`status`,`updated_at`),
      KEY `idx_ai_intake_user_updated` (`user_id`,`updated_at`),
      KEY `idx_ai_intake_ticket` (`confirmed_ticket_id`),
      CONSTRAINT `fk_ai_intake_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_ai_intake_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
      CONSTRAINT `fk_ai_intake_ticket` FOREIGN KEY (`confirmed_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
