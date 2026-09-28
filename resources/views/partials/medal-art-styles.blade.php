{{-- สไตล์ของ partials/medal-art — แยกไว้เพื่อให้ทุกหน้าที่วาดเหรียญหน้าตาตรงกัน --}}
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,500,1,0&display=block" rel="stylesheet">
<style>
    .medal-art {
        position: relative;
        width: var(--size);
        height: calc(var(--size) * 1.18);
        flex: none;
    }

    .medal-art__custom {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    /* ริบบิ้นสองเส้นไขว้เป็นตัว V เหนือดวงเหรียญ */
    .medal-art__ribbon {
        position: absolute;
        left: 0;
        right: 0;
        top: 0;
        height: calc(var(--size) * 0.42);
    }

    .medal-art__ribbon span {
        position: absolute;
        top: 0;
        width: calc(var(--size) * 0.2);
        height: calc(var(--size) * 0.5);
        border-left: calc(var(--size) * 0.07) solid color-mix(in srgb, var(--medal) 78%, #000);
        border-right: calc(var(--size) * 0.07) solid color-mix(in srgb, var(--medal) 78%, #000);
        background-color: #fff;
        box-sizing: border-box;
    }

    .medal-art__ribbon span:first-child {
        left: 22%;
        transform: rotate(-18deg);
        transform-origin: top center;
    }

    .medal-art__ribbon span:last-child {
        right: 22%;
        transform: rotate(18deg);
        transform-origin: top center;
    }

    .medal-art__rim {
        position: absolute;
        left: 0;
        bottom: 0;
        width: var(--size);
        height: var(--size);
        border-radius: 50%;
        background: #D9A441;
        padding: calc(var(--size) * 0.06);
    }

    .medal-art__disc {
        position: relative;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: var(--medal);
        color: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 0 16%;
    }

    .medal-art__ring {
        position: absolute;
        inset: calc(var(--size) * 0.04);
        border-radius: 50%;
        border: max(1.5px, calc(var(--size) * 0.012)) solid rgba(255, 255, 255, .45);
    }

    .medal-art__icon {
        font-size: calc(var(--size) * 0.28);
        line-height: 1;
    }

    .medal-art__name {
        margin-top: calc(var(--size) * 0.03);
        font-size: calc(var(--size) * 0.075);
        font-weight: 800;
        line-height: 1.25;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .medal-art__kicker {
        margin-top: calc(var(--size) * 0.025);
        font-size: calc(var(--size) * 0.05);
        font-weight: 800;
        letter-spacing: .12em;
        opacity: .85;
    }
</style>
