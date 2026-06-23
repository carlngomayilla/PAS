"""Plotly builders for the PAS dashboard.

The Laravel dashboard treats this script as optional. If pandas/plotly are not
available, the PHP-rendered fallback remains visible and the page still loads.
"""

from __future__ import annotations

import json
import sys
from typing import Any

try:
    import pandas as pd
    import plotly.graph_objects as go
except Exception as exc:  # pragma: no cover - handled by Laravel fallback
    sys.stderr.write(f"dashboard chart dependencies unavailable: {exc}")
    sys.exit(2)


PALETTE = {
    "green": "#20C76B",
    "orange": "#F26522",
    "red": "#D92D20",
    "blue": "#0F5B66",
    "gold": "#F4B400",
    "muted": "#94A3B8",
    "ink": "#17324A",
}


def clamp(value: Any, default: float = 0.0) -> float:
    try:
        number = float(value)
    except (TypeError, ValueError):
        number = default

    return max(0.0, min(100.0, number))


def score_color(value: float, threshold: float) -> str:
    if value >= threshold:
        return PALETTE["green"]
    if value >= max(55.0, threshold * 0.75):
        return PALETTE["orange"]
    if value > 0:
        return PALETTE["red"]
    return PALETTE["muted"]


def figure_json(fig: go.Figure) -> dict[str, Any]:
    return json.loads(fig.to_json())


def build_gauge(summary: dict[str, Any], threshold: float) -> go.Figure:
    value = clamp(summary.get("average_score"))
    fig = go.Figure(
        go.Indicator(
            mode="gauge+number+delta",
            value=value,
            number={"suffix": "%", "font": {"size": 36}},
            delta={"reference": threshold, "increasing": {"color": PALETTE["green"]}, "decreasing": {"color": PALETTE["red"]}},
            title={"text": "Performance moyenne des agents"},
            gauge={
                "axis": {"range": [0, 100], "tickwidth": 1},
                "bar": {"color": score_color(value, threshold), "thickness": 0.28},
                "bgcolor": "rgba(0,0,0,0)",
                "borderwidth": 0,
                "steps": [
                    {"range": [0, 55], "color": "rgba(217,45,32,0.20)"},
                    {"range": [55, threshold], "color": "rgba(242,101,34,0.22)"},
                    {"range": [threshold, 100], "color": "rgba(32,199,107,0.20)"},
                ],
                "threshold": {"line": {"color": PALETTE["gold"], "width": 4}, "thickness": 0.8, "value": threshold},
            },
        )
    )
    fig.update_layout(height=300, margin={"l": 18, "r": 18, "t": 54, "b": 20})

    return fig


def build_top_agents(df: pd.DataFrame, threshold: float) -> go.Figure:
    top = df.sort_values("score_global", ascending=False).head(10).sort_values("score_global")
    colors = [score_color(value, threshold) for value in top["score_global"]]
    fig = go.Figure(
        go.Bar(
            x=top["score_global"],
            y=top["agent"],
            orientation="h",
            marker={"color": colors},
            text=[f"{value:.0f}%" for value in top["score_global"]],
            textposition="outside",
            hovertemplate="<b>%{y}</b><br>Score: %{x:.1f}%<extra></extra>",
        )
    )
    fig.update_layout(
        height=max(300, 42 * max(1, len(top))),
        margin={"l": 118, "r": 34, "t": 24, "b": 38},
        xaxis={"range": [0, 105], "title": "Score global"},
        yaxis={"title": ""},
    )

    return fig


def build_3d(df: pd.DataFrame, threshold: float) -> go.Figure:
    marker_sizes = (df["actions_late"].fillna(0).astype(float) * 3 + 8).clip(8, 34)
    custom = df[["agent", "score_execution", "score_delay", "score_quality", "actions_late"]].to_numpy()
    fig = go.Figure(
        go.Scatter3d(
            x=df["actions_assigned"],
            y=df["actions_closed"],
            z=df["score_global"],
            mode="markers",
            marker={
                "size": marker_sizes,
                "color": df["score_global"],
                "colorscale": [[0, PALETTE["red"]], [0.55, PALETTE["orange"]], [1, PALETTE["green"]]],
                "cmin": 0,
                "cmax": 100,
                "opacity": 0.88,
                "colorbar": {"title": "Score"},
            },
            customdata=custom,
            hovertemplate=(
                "<b>%{customdata[0]}</b><br>"
                "Assignees: %{x}<br>"
                "Cloturees: %{y}<br>"
                "Score global: %{z:.1f}%<br>"
                "Execution: %{customdata[1]:.1f}%<br>"
                "Delai: %{customdata[2]:.1f}%<br>"
                "Score reference: %{customdata[3]:.1f}%<br>"
                "Retards: %{customdata[4]}<extra></extra>"
            ),
        )
    )
    fig.update_layout(
        height=420,
        margin={"l": 0, "r": 0, "t": 18, "b": 0},
        scene={
            "xaxis_title": "Actions assignees",
            "yaxis_title": "Cloturees",
            "zaxis_title": "Score global",
            "zaxis": {"range": [0, 100]},
        },
        annotations=[
            {
                "text": f"Seuil {threshold:.0f}%",
                "showarrow": False,
                "xref": "paper",
                "yref": "paper",
                "x": 0.02,
                "y": 0.98,
                "font": {"color": PALETTE["gold"], "size": 12},
            }
        ],
    )

    return fig


def build_heatmap(rows: list[dict[str, Any]], top_agents: list[str]) -> go.Figure:
    records: list[dict[str, Any]] = []
    for row in rows:
        agent = str(row.get("agent") or "Non assigne")
        service_scores = row.get("service_scores") or {}
        if isinstance(service_scores, dict) and service_scores:
            for service, score in service_scores.items():
                records.append({"agent": agent, "service": str(service), "score": clamp(score)})
        else:
            records.append({"agent": agent, "service": str(row.get("service") or "Non renseigne"), "score": clamp(row.get("score_global"))})

    heat_df = pd.DataFrame.from_records(records)
    if heat_df.empty:
        heat_df = pd.DataFrame([{"agent": "Non assigne", "service": "Non renseigne", "score": 0.0}])

    pivot = (
        heat_df[heat_df["agent"].isin(top_agents)]
        .pivot_table(index="service", columns="agent", values="score", aggfunc="mean", fill_value=0)
        .sort_index()
    )
    fig = go.Figure(
        go.Heatmap(
            z=pivot.values,
            x=list(pivot.columns),
            y=list(pivot.index),
            colorscale=[[0, PALETTE["red"]], [0.55, PALETTE["orange"]], [1, PALETTE["green"]]],
            zmin=0,
            zmax=100,
            hovertemplate="<b>%{x}</b><br>%{y}<br>Score: %{z:.1f}%<extra></extra>",
            colorbar={"title": "Score"},
        )
    )
    fig.update_layout(height=max(300, 44 * max(1, len(pivot.index))), margin={"l": 120, "r": 20, "t": 24, "b": 90})

    return fig


def main() -> int:
    payload = json.load(sys.stdin)
    rows = payload.get("rows") or []
    threshold = clamp(payload.get("threshold"), 77.0)

    if not rows:
        print(json.dumps({"figures": {}}, ensure_ascii=False))
        return 0

    df = pd.DataFrame.from_records(rows)
    numeric_columns = [
        "actions_assigned",
        "actions_closed",
        "actions_late",
        "score_execution",
        "score_delay",
        "score_quality",
        "score_global",
    ]
    for column in numeric_columns:
        if column not in df.columns:
            df[column] = 0
        df[column] = pd.to_numeric(df[column], errors="coerce").fillna(0)

    if "agent" not in df.columns:
        df["agent"] = "Non assigne"

    top_agents = list(df.sort_values("score_global", ascending=False).head(10)["agent"])
    figures = {
        "agent_gauge": figure_json(build_gauge(payload.get("summary") or {}, threshold)),
        "agent_top": figure_json(build_top_agents(df, threshold)),
        "agent_3d": figure_json(build_3d(df, threshold)),
        "agent_heatmap": figure_json(build_heatmap(rows, top_agents)),
    }
    print(json.dumps({"figures": figures}, ensure_ascii=False))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
