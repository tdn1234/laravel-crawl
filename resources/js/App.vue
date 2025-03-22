<template>
    <div class="container">
        <h1>Laravel 12 + Vue.js + Docker</h1>
        <p>Your application is running with Docker!</p>
        <ul class="tech-list">
            <li>PHP 8.2</li>
            <li>MariaDB</li>
            <li>Redis Cache</li>
            <li>Vue.js</li>
        </ul>
    </div>
</template>

<script>
export default {
    name: 'App'
}
</script>

<style>
.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem;
    text-align: center;
    font-family: 'Arial', sans-serif;
}
.tech-list {
    display: flex;
    justify-content: center;
    list-style-type: none;
    padding: 0;
    gap: 20px;
}
.tech-list li {
    background-color: #4CAF50;
    color: white;
    padding: 8px 16px;
    border-radius: 4px;
}
</style>