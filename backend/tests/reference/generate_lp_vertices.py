#!/usr/bin/env python3
"""Independent two-variable LP vertex oracle using only Python Fraction, no SciPy/PHP."""
from fractions import Fraction as F
from itertools import combinations
from pathlib import Path
import json
import random

rng = random.Random(241126)
cases = []
for index in range(40):
    cap = F(rng.randrange(15, 80), 10)
    amax = F(rng.randrange(5, 30), 10)
    bmax = F(rng.randrange(5, 30), 10)
    group = F(rng.randrange(10, 65), 10)
    slopes = (F(2), F(1))
    inequalities = [(F(-1), F(0), F(0)), (F(0), F(-1), F(0)),
                    (F(1), F(0), amax), (F(0), F(1), bmax),
                    (F(1), F(1), cap), (F(1), F(1), group)]
    vertices = []
    for a, b in combinations(inequalities, 2):
        determinant = a[0] * b[1] - a[1] * b[0]
        if not determinant:
            continue
        x = (a[2] * b[1] - a[1] * b[2]) / determinant
        y = (a[0] * b[2] - a[2] * b[0]) / determinant
        if all(c[0] * x + c[1] * y <= c[2] for c in inequalities):
            vertices.append((x, y))
    best = max(vertices, key=lambda v: (slopes[0] * v[0] + slopes[1] * v[1], v[0]))
    def decimal(x):
        # All constraints use tenths and 0/1 coefficients, so these denominators divide10.
        assert (x * 10).denominator == 1
        sign = '-' if x < 0 else ''
        scaled = abs(int(x * 10))
        return sign + str(scaled // 10) + '.' + str(scaled % 10)
    cases.append({'cap': decimal(cap), 'maximum_a': decimal(amax), 'maximum_b': decimal(bmax), 'group_cap': decimal(group),
                  'numeric_candidate': {'a': float(best[0]), 'b': float(best[1])},
                  'exact_optimum': {'a': decimal(best[0]), 'b': decimal(best[1])},
                  'exact_objective': decimal(2 * best[0] + best[1])})
path = Path(__file__).resolve().parent.parent / 'Fixtures' / 'exact-lp-vertices.json'
path.write_text(json.dumps({'reference': 'Python Fraction intersection enumeration, no SciPy or PHP, seed241126', 'cases': cases}, indent=2))
