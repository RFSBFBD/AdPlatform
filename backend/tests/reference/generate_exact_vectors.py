#!/usr/bin/env python3
"""Regenerate arithmetic fixtures independently of PHP/Brick with Decimal/Fraction."""
from decimal import Decimal, localcontext
from fractions import Fraction
from pathlib import Path
import json
import random

rng = random.Random(241126)
cases = []
with localcontext() as context:
    context.prec = 1000
    for _ in range(120):
        a = str(rng.randint(0, 10**30)) + '.' + str(rng.randint(0, 10**12)).zfill(12)
        b = str(rng.randint(1, 10**25)) + '.' + str(rng.randint(0, 10**15)).zfill(15)
        x, y = Decimal(a), Decimal(b)
        ratio = Fraction(x) / Fraction(y)
        cases.append({'a': a, 'b': b, 'sum': str(x + y), 'product': str(x * y),
                      'ratio': {'numerator': str(ratio.numerator), 'denominator': str(ratio.denominator)}})
binary = []
for token in ['0', '0.1', '0.000001', '9007199254740993', '123456789.123456789', '-0.125', '1e-100', '5e-324']:
    number = float(token)
    fraction = Fraction.from_float(number)
    binary.append({'lexeme': token, 'float': number, 'numerator': str(fraction.numerator), 'denominator': str(fraction.denominator)})
path = Path(__file__).resolve().parent.parent / 'Fixtures' / 'exact-independent-reference.json'
path.write_text(json.dumps({'reference': 'Python3 decimal and fractions, deterministic seed241126, no PHP/Brick calculations',
                            'cases': cases, 'binary64': binary}, indent=2))
