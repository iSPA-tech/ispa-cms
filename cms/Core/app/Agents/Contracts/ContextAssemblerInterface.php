<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

use Cms\Core\Models\Agents\AgentRun;

/**
 * Contract for assembling the context snapshot fed to the reasoning engine.
 *
 * The context assembler gathers run metadata, target information, working memory,
 * and checkpoint state into a structured array that the reasoning engine can
 * consume to make planning decisions.
 *
 * Implementors: {@see \Cms\Core\Agents\Runtime\DefaultContextAssembler}.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface ContextAssemblerInterface
{
    /**
     * Assemble a context snapshot for the given agent run.
     *
     * @param  AgentRun $run The current agent run whose context is being assembled.
     * @return array<string, mixed> The assembled context snapshot.
     */
    public function assemble(AgentRun $run): array;
}
