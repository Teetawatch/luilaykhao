{{-- สไตล์ของ partials/medal-art — แยกไว้เพื่อให้ทุกหน้าที่วาดเหรียญหน้าตาตรงกัน --}}
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,500,1,0&display=block" rel="stylesheet">
<style>
    .medal-art {
        width: var(--size);
        height: calc(var(--size) * 1.18);
        flex: none;
    }

    .medal-art svg { display: block; overflow: visible; }

    .medal-art__custom {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .medal-art__ring,
    .medal-art__banner {
        font-family: 'Anuphan', -apple-system, 'Segoe UI', sans-serif;
        font-weight: 800;
        letter-spacing: .6px;
    }

    .medal-art__icon {
        font-family: 'Material Symbols Rounded';
        font-weight: normal;
        font-variation-settings: 'FILL' 1;
    }

    .medal-art__name {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #fff;
        font-family: 'Anuphan', -apple-system, 'Segoe UI', sans-serif;
        font-weight: 800;
        line-height: 1.2;
        overflow: hidden;
    }
</style>
