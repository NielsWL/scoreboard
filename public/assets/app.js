(() => {
    const body = document.body;
    const gameId = body?.dataset?.gameId;
    if (!gameId) {
        return;
    }

    const periodLabel = (period) => {
        const p = Number(period) || 1;
        if (p <= 4) {
            return `Q${p}`;
        }
        return `OT${p - 4}`;
    };

    const buildQuarterTable = (history, homeLabel, awayLabel) => {
        const table = document.querySelector('.quarter-table');
        if (!table) {
            return;
        }
        let parsed = [];
        try {
            parsed = JSON.parse(history || '[]');
        } catch (error) {
            parsed = [];
        }

        const entries = new Map();
        let maxPeriod = 4;
        parsed.forEach((entry) => {
            const period = Number(entry?.period || 0);
            if (period > 0) {
                entries.set(period, {
                    home: Number(entry?.home || 0),
                    away: Number(entry?.away || 0),
                });
                maxPeriod = Math.max(maxPeriod, period);
            }
        });

        const quarterHome = [];
        const quarterAway = [];
        let prevHome = 0;
        let prevAway = 0;
        for (let period = 1; period <= maxPeriod; period += 1) {
            if (entries.has(period)) {
                const current = entries.get(period);
                quarterHome.push(current.home - prevHome);
                quarterAway.push(current.away - prevAway);
                prevHome = current.home;
                prevAway = current.away;
            } else {
                quarterHome.push(null);
                quarterAway.push(null);
            }
        }

        const headCells = ['<th>Team</th>'];
        for (let period = 1; period <= maxPeriod; period += 1) {
            headCells.push(`<th>${periodLabel(period)}</th>`);
        }

        const homeCells = quarterHome.map((value) => `<td>${value === null ? '-' : value}</td>`).join('');
        const awayCells = quarterAway.map((value) => `<td>${value === null ? '-' : value}</td>`).join('');

        table.innerHTML = `
            <thead>
                <tr>${headCells.join('')}</tr>
            </thead>
            <tbody>
                <tr>
                    <td>${homeLabel}</td>
                    ${homeCells}
                </tr>
                <tr>
                    <td>${awayLabel}</td>
                    ${awayCells}
                </tr>
            </tbody>
        `;
    };

    const buildPlayerList = (selector, players) => {
        const container = document.querySelector(selector);
        if (!container) {
            return;
        }
        const maxFouls = 5;
        container.innerHTML = players.map((player) => {
            const foulClass = Number(player.fouls) >= 5 ? 'foul-out' : '';
            const number = player.number ? `${player.number} ` : '';
            const lamps = Array.from({ length: maxFouls }, (_, index) => {
                const active = Number(player.fouls) >= index + 1 ? 'active' : '';
                return `<span class="${active}"></span>`;
            }).join('');
            return `
                <li class="${foulClass}">
                    <span>${number}${player.name}</span>
                    <div class="foul-lamps" aria-label="Fouls: ${player.fouls}">
                        ${lamps}
                    </div>
                </li>
            `;
        }).join('');
    };

    const updateView = async () => {
        try {
            const response = await fetch(`/admin/api.php?action=get&id=${gameId}`);
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            const game = payload.game;
            if (!game) {
                return;
            }

            const homeScore = document.querySelector('[data-home-score]');
            const awayScore = document.querySelector('[data-away-score]');
            const period = document.querySelector('[data-period]');
            if (homeScore) homeScore.textContent = game.home_score;
            if (awayScore) awayScore.textContent = game.away_score;
            if (period) period.textContent = periodLabel(game.period);

            const teamFoulsHome = document.querySelector('[data-team-fouls-home]');
            const teamFoulsAway = document.querySelector('[data-team-fouls-away]');
            const timeoutsHome = document.querySelector('[data-timeouts-home]');
            const timeoutsAway = document.querySelector('[data-timeouts-away]');
            if (teamFoulsHome) teamFoulsHome.textContent = game.team_fouls_home;
            if (teamFoulsAway) teamFoulsAway.textContent = game.team_fouls_away;
            if (timeoutsHome) timeoutsHome.textContent = game.timeouts_home;
            if (timeoutsAway) timeoutsAway.textContent = game.timeouts_away;

            buildQuarterTable(game.quarter_history, game.team_home, game.team_away);
            buildPlayerList('.view-side-home .player-list', payload.players.home || []);
            buildPlayerList('.view-side-away .player-list', payload.players.away || []);
        } catch (error) {
            console.warn('Polling failed', error);
        }
    };

    setInterval(updateView, 2000);
})();
