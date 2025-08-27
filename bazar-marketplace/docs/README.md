# Documentation

This directory contains all documentation for the Bazar Marketplace project.

## Contents

- **API.md** - Complete API documentation with examples
- **DEPLOYMENT.md** - Production deployment guidelines
- **SECURITY.md** - Security best practices and guidelines
- **CONTRIBUTING.md** - Guide for contributors
- **CHANGELOG.md** - Version history and changes
- **ARCHITECTURE.md** - System architecture overview

## Quick Links

### For Developers
- [Getting Started](../README.md#installation)
- [API Documentation](API.md)
- [Architecture Overview](ARCHITECTURE.md)
- [Contributing Guidelines](CONTRIBUTING.md)

### For DevOps
- [Deployment Guide](DEPLOYMENT.md)
- [Security Guidelines](SECURITY.md)
- [Docker Configuration](../docker/)

### For Users
- [User Guide](USER_GUIDE.md)
- [FAQ](FAQ.md)
- [Troubleshooting](TROUBLESHOOTING.md)

## Documentation Standards

When contributing to documentation:

1. **Format**: Use Markdown for all documentation
2. **Structure**: Follow the established structure and naming conventions
3. **Clarity**: Write clear, concise explanations with examples
4. **Updates**: Keep documentation up-to-date with code changes
5. **Links**: Use relative links for internal references
6. **Images**: Store images in `docs/images/` directory

## Generating Documentation

### API Documentation
```bash
# Generate API docs from code comments
composer docs:api
```

### Code Documentation
```bash
# Generate code documentation with phpDocumentor
composer docs:code
```

### Database Documentation
```bash
# Generate database schema documentation
composer docs:database
```

## Contributing

To contribute to documentation:

1. Fork the repository
2. Create a documentation branch
3. Make your changes
4. Test all links and examples
5. Submit a pull request

## Feedback

For documentation feedback:
- Create an issue with the `documentation` label
- Email: docs@bazar.com
- Suggest improvements via pull requests