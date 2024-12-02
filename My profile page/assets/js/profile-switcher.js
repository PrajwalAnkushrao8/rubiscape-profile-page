document.addEventListener('DOMContentLoaded', function() {
    // Function to switch between profile and courses content
    function switchContent(contentName) {
        // Remove active class from all sidebar items
        document.querySelectorAll('.sidebar-item').forEach(function(item) {
            item.classList.remove('active');
        });
        
        // Remove active class from all content sections
        document.querySelectorAll('.content > div').forEach(function(content) {
            content.classList.remove('active');
        });
        
        // Add active class to selected sidebar item
        document.getElementById(contentName + 'Item').classList.add('active');
        
        // Add active class to selected content section
        document.getElementById(contentName + 'Content').classList.add('active');
    }

    // Add event listeners to sidebar items
    document.getElementById('profileItem').addEventListener('click', function() {
        switchContent('profile');
    });

    document.getElementById('coursesItem').addEventListener('click', function() {
        switchContent('courses');
    });
});
