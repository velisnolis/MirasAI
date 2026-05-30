#!/usr/bin/env node

import { runCli } from '../src/commands.mjs';

const exitCode = await runCli();
process.exit(exitCode);
