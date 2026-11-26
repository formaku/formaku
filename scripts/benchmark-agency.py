"""Create and score the Formaku agency pilot worksheet. Requires Python 3.9+."""

import argparse
import json
import math
import statistics
import sys
from pathlib import Path


PLATFORMS = ("formaku", "wordpress")
BRIEFS = ("meris", "lumet")
CHECKS = ("request_and_reply", "customer_isolation", "keyboard_and_mobile", "email", "restore")
COSTS = ("ai", "licenses", "hosting")


def template():
    agencies = []
    for agency in range(3):
        runs = []
        for index, brief in enumerate(BRIEFS):
            order = PLATFORMS if (agency + index) % 2 == 0 else PLATFORMS[::-1]
            for position, platform in enumerate(order, start=1):
                runs.append({
                    "brief": brief, "platform": platform, "order": position, "stack": None,
                    "human_minutes": None, "correction_rounds": None,
                    "checks": dict.fromkeys(CHECKS), "cost": dict.fromkeys(COSTS),
                    "receipt": None,
                })
        agencies.append({"agency": f"A{agency + 1}", "runs": runs,
                         "next_real_formaku_job": None, "next_job_receipt": None})
    return {"format": 1, "evidence": None, "currency": "PLN", "agencies": agencies}


def number(value, path, minimum=0, integer=False):
    if value is None:
        return
    if type(value) not in (int, float) or not math.isfinite(value) or value < minimum:
        raise ValueError(f"{path} must be a finite number >= {minimum}, or null.")
    if integer and type(value) is not int:
        raise ValueError(f"{path} must be an integer or null.")


def boolean(value, path):
    if value is not None and type(value) is not bool:
        raise ValueError(f"{path} must be true, false or null.")


def nonblank(value, path):
    if value is not None and (not isinstance(value, str) or not value.strip()):
        raise ValueError(f"{path} must be a nonempty string or null.")


def summarize(data):
    if data["format"] != 1 or data["evidence"] not in (None, "synthetic", "pilot"):
        raise ValueError("Use format 1 and evidence null, synthetic or pilot.")
    currency = data["currency"]
    if not isinstance(currency, str) or len(currency) != 3 or not currency.isascii() or not currency.isalpha() or not currency.isupper():
        raise ValueError("Use a three-letter uppercase currency code consistently.")
    reference = template()
    agencies = data["agencies"]
    if not isinstance(agencies, list) or len(agencies) != 3:
        raise ValueError("The pilot requires exactly three agency rows.")
    totals = {p: {"minutes": [], "corrections": 0, "cost": dict.fromkeys(COSTS, 0)} for p in PLATFORMS}
    failures = []
    missing = []
    observed_next_jobs = 0
    followups = 0
    for group, planned in zip(agencies, reference["agencies"]):
        name = planned["agency"]
        if group["agency"] != name or not isinstance(group["runs"], list) or len(group["runs"]) != 4:
            raise ValueError(f"Retain four scheduled runs for agency {name}.")
        boolean(group["next_real_formaku_job"], f"{name}.next_real_formaku_job")
        nonblank(group["next_job_receipt"], f"{name}.next_job_receipt")
        if group["next_real_formaku_job"] is not None and group["next_job_receipt"]:
            followups += 1
            observed_next_jobs += int(group["next_real_formaku_job"])
        for row, slot in zip(group["runs"], planned["runs"]):
            platform = slot["platform"]
            key = f"{name}/{slot['brief']}/{platform}"
            if any(row[k] != slot[k] for k in ("brief", "platform", "order")):
                raise ValueError(f"Keep the assigned counterbalanced slot for {key}.")
            if set(row["checks"]) != set(CHECKS) or set(row["cost"]) != set(COSTS):
                raise ValueError(f"Missing or unexpected checks or costs for {key}.")
            number(row["human_minutes"], key + ".human_minutes", minimum=0.01)
            number(row["correction_rounds"], key + ".correction_rounds", integer=True)
            nonblank(row["stack"], key + ".stack")
            nonblank(row["receipt"], key + ".receipt")
            for check, value in row["checks"].items():
                boolean(value, key + "." + check)
                if value is False:
                    failures.append(key + "/" + check)
            for category, cost in row["cost"].items():
                number(cost, key + "." + category)
            values = [row["human_minutes"], row["correction_rounds"], row["stack"], row["receipt"],
                      *row["checks"].values(), *row["cost"].values()]
            if any(value is None for value in values):
                missing.append(key)
                continue
            summary = totals[platform]
            summary["minutes"].append(row["human_minutes"])
            summary["corrections"] += row["correction_rounds"]
            for category in COSTS:
                summary["cost"][category] += row["cost"][category]

    result = {"evidence": data["evidence"], "currency": currency, "recorded_runs": 12 - len(missing),
              "incomplete_runs": missing, "failed_final_checks": failures,
              "documented_followups": followups, "repeat_agencies": observed_next_jobs,
              "platforms": None, "time_saved_percent": None, "decision": "incomplete"}
    if not missing and not failures:
        for summary in totals.values():
            summary["median_human_minutes"] = statistics.median(summary.pop("minutes"))
            summary["cost"] = {category: round(value, 2) for category, value in summary["cost"].items()}
        result["platforms"] = totals
        speedup = 1 - totals["formaku"]["median_human_minutes"] / totals["wordpress"]["median_human_minutes"]
        result["time_saved_percent"] = round(100 * speedup, 2)
        if data["evidence"] == "pilot":
            if speedup < 0.40 or (followups == 3 and observed_next_jobs < 2):
                result["decision"] = "threshold_missed"
            elif observed_next_jobs >= 2:
                result["decision"] = "pilot_threshold_met"
    elif not missing and failures and data["evidence"] == "pilot":
        result["decision"] = "acceptance_failed"
    if data["evidence"] == "synthetic":
        result["decision"] = "synthetic_only"
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("template", help="Print a blank twelve-run worksheet")
    score = commands.add_parser("score", help="Validate and score a saved worksheet")
    score.add_argument("worksheet", type=Path)
    args = parser.parse_args()
    try:
        result = template() if args.command == "template" else summarize(json.loads(args.worksheet.read_text()))
    except (KeyError, OSError, TypeError, ValueError) as error:
        parser.exit(2, f"Invalid worksheet: {error}\n")
    json.dump(result, sys.stdout, indent=2, ensure_ascii=False, allow_nan=False)
    print()


if __name__ == "__main__":
    main()
