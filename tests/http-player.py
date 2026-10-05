#!/usr/bin/env python3
"""Optional DOM navigation test: Node.js and jsdom 27.0.1 must be available."""
import importlib.util
import os
from pathlib import Path
import subprocess

spec = importlib.util.spec_from_file_location("auth_http", Path(__file__).with_name("http-auth.py"))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)

with auth.fixture(schedule=True) as values:
    subprocess.run(
        ["node", str(Path(__file__).with_name("player-navigation.cjs"))],
        env=dict(os.environ, DJ_TEST_ORIGIN=values[0]), check=True,
    )
