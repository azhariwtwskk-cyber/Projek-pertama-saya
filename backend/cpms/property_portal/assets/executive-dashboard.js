(() => {
    const canvas = document.getElementById(
        'complaintTrendChart'
    );

    const data = window.cpmsDashboardTrend;

    if (!canvas || !data) {
        return;
    }

    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    const draw = () => {
        const ratio = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        const width = Math.max(rect.width, 320);
        const height = 290;

        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        canvas.style.height = `${height}px`;

        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);

        const labels = Array.isArray(data.labels)
            ? data.labels
            : [];

        const values = Array.isArray(data.values)
            ? data.values.map(Number)
            : [];

        const padding = {
            top: 20,
            right: 20,
            bottom: 42,
            left: 34,
        };

        const chartWidth =
            width - padding.left - padding.right;

        const chartHeight =
            height - padding.top - padding.bottom;

        const maximum = Math.max(
            4,
            ...values
        );

        context.lineWidth = 1;
        context.strokeStyle = '#e8edf4';
        context.fillStyle = '#8290a3';
        context.font = '10px Segoe UI, Arial';

        const gridLines = 4;

        for (let index = 0; index <= gridLines; index += 1) {
            const y =
                padding.top
                + (chartHeight / gridLines) * index;

            context.beginPath();
            context.moveTo(padding.left, y);
            context.lineTo(width - padding.right, y);
            context.stroke();

            const labelValue = Math.round(
                maximum
                - (maximum / gridLines) * index
            );

            context.fillText(
                String(labelValue),
                4,
                y + 3
            );
        }

        if (!values.length) {
            return;
        }

        const step = values.length > 1
            ? chartWidth / (values.length - 1)
            : chartWidth;

        const points = values.map((value, index) => {
            const x = padding.left + step * index;
            const y =
                padding.top
                + chartHeight
                - (value / maximum) * chartHeight;

            return {x, y, value};
        });

        const primary = getComputedStyle(
            document.documentElement
        ).getPropertyValue('--property-primary').trim()
            || '#2563eb';

        const gradient = context.createLinearGradient(
            0,
            padding.top,
            0,
            padding.top + chartHeight
        );

        gradient.addColorStop(
            0,
            `${primary}38`
        );
        gradient.addColorStop(
            1,
            `${primary}00`
        );

        context.beginPath();
        context.moveTo(points[0].x, padding.top + chartHeight);

        points.forEach((point) => {
            context.lineTo(point.x, point.y);
        });

        context.lineTo(
            points[points.length - 1].x,
            padding.top + chartHeight
        );

        context.closePath();
        context.fillStyle = gradient;
        context.fill();

        context.beginPath();

        points.forEach((point, index) => {
            if (index === 0) {
                context.moveTo(point.x, point.y);
            } else {
                context.lineTo(point.x, point.y);
            }
        });

        context.strokeStyle = primary;
        context.lineWidth = 3;
        context.lineJoin = 'round';
        context.lineCap = 'round';
        context.stroke();

        points.forEach((point, index) => {
            context.beginPath();
            context.arc(point.x, point.y, 4, 0, Math.PI * 2);
            context.fillStyle = '#fff';
            context.fill();
            context.strokeStyle = primary;
            context.lineWidth = 2;
            context.stroke();

            context.fillStyle = '#8290a3';
            context.font = '10px Segoe UI, Arial';
            context.textAlign = 'center';

            context.fillText(
                labels[index] || '',
                point.x,
                height - 14
            );
        });

        context.textAlign = 'left';
    };

    draw();

    let resizeTimer;

    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(draw, 120);
    });
})();
