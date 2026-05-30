#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = process.argv[2] ?? path.resolve(currentDir, '../fixtures/joomla-tools-list.min.json');
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

const errors = validateToolsList(fixture);

if (errors.length > 0) {
  console.error(errors.map((error) => `- ${error}`).join('\n'));
  process.exit(1);
}

console.log(`Fixture OK: ${fixturePath}`);

export function validateToolsList(payload) {
  const errors = [];

  if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
    return ['payload must be an object'];
  }

  if (!Array.isArray(payload.tools)) {
    return ['payload.tools must be an array'];
  }

  payload.tools.forEach((tool, index) => {
    const prefix = `tools[${index}]`;

    if (tool === null || typeof tool !== 'object' || Array.isArray(tool)) {
      errors.push(`${prefix} must be an object`);
      return;
    }

    if (typeof tool.name !== 'string' || tool.name.trim() === '') {
      errors.push(`${prefix}.name must be a non-empty string`);
    }

    if (typeof tool.description !== 'string' || tool.description.trim() === '') {
      errors.push(`${prefix}.description must be a non-empty string`);
    }

    const inputSchema = tool.inputSchema;
    if (inputSchema === null || typeof inputSchema !== 'object' || Array.isArray(inputSchema)) {
      errors.push(`${prefix}.inputSchema must be an object`);
    } else {
      if (inputSchema.type !== 'object') {
        errors.push(`${prefix}.inputSchema.type must be "object"`);
      }
      if (
        inputSchema.properties === null
        || typeof inputSchema.properties !== 'object'
        || Array.isArray(inputSchema.properties)
      ) {
        errors.push(`${prefix}.inputSchema.properties must be an object`);
      }
      if (inputSchema.required !== undefined && !Array.isArray(inputSchema.required)) {
        errors.push(`${prefix}.inputSchema.required must be an array when present`);
      }
    }

    const metadata = tool.metadata;
    if (metadata === null || typeof metadata !== 'object' || Array.isArray(metadata)) {
      errors.push(`${prefix}.metadata must be an object`);
    } else {
      if (!['read', 'safe_write', 'guarded_write', 'dangerous_exec'].includes(metadata.risk_level)) {
        errors.push(`${prefix}.metadata.risk_level is invalid`);
      }
      if (!['direct', 'validate_then_apply', 'dry_run_confirm_if_match', 'elevation_required'].includes(metadata.workflow_hint)) {
        errors.push(`${prefix}.metadata.workflow_hint is invalid`);
      }
      if (!['essential', 'advanced'].includes(metadata.surface)) {
        errors.push(`${prefix}.metadata.surface is invalid`);
      }
      if (metadata.platforms !== undefined && !Array.isArray(metadata.platforms)) {
        errors.push(`${prefix}.metadata.platforms must be an array when present`);
      }
    }

    const annotations = tool.annotations;
    if (annotations !== undefined && (annotations === null || typeof annotations !== 'object' || Array.isArray(annotations))) {
      errors.push(`${prefix}.annotations must be an object when present`);
    }
  });

  return errors;
}
