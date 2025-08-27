const path = require('path');
const HtmlWebpackPlugin = require('html-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CopyWebpackPlugin = require('copy-webpack-plugin');

module.exports = (env, argv) => {
    const isProduction = argv.mode === 'production';
    
    return {
        entry: {
            app: './frontend/js/app.js',
            vendor: ['bootstrap', 'jquery']
        },
        
        output: {
            path: path.resolve(__dirname, 'public/dist'),
            filename: isProduction ? '[name].[contenthash].js' : '[name].js',
            clean: true,
        },
        
        module: {
            rules: [
                {
                    test: /\.js$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            presets: ['@babel/preset-env']
                        }
                    }
                },
                {
                    test: /\.(css|scss|sass)$/,
                    use: [
                        isProduction ? MiniCssExtractPlugin.loader : 'style-loader',
                        'css-loader',
                        'sass-loader'
                    ]
                },
                {
                    test: /\.(png|jpe?g|gif|svg|ico)$/i,
                    type: 'asset/resource',
                    generator: {
                        filename: 'images/[name][ext]'
                    }
                },
                {
                    test: /\.(woff|woff2|eot|ttf|otf)$/i,
                    type: 'asset/resource',
                    generator: {
                        filename: 'fonts/[name][ext]'
                    }
                }
            ]
        },
        
        plugins: [
            new MiniCssExtractPlugin({
                filename: isProduction ? '[name].[contenthash].css' : '[name].css'
            }),
            
            new CopyWebpackPlugin({
                patterns: [
                    {
                        from: 'frontend/assets/images',
                        to: 'images',
                        noErrorOnMissing: true
                    },
                    {
                        from: 'frontend/assets/fonts',
                        to: 'fonts',
                        noErrorOnMissing: true
                    }
                ]
            })
        ],
        
        optimization: {
            splitChunks: {
                chunks: 'all',
                cacheGroups: {
                    vendor: {
                        test: /[\\/]node_modules[\\/]/,
                        name: 'vendors',
                        chunks: 'all',
                    }
                }
            }
        },
        
        devServer: {
            static: {
                directory: path.join(__dirname, 'public'),
            },
            compress: true,
            port: 3000,
            hot: true,
            open: true,
            proxy: {
                '/api': {
                    target: 'http://localhost:8000',
                    changeOrigin: true
                }
            }
        },
        
        devtool: isProduction ? 'source-map' : 'eval-source-map'
    };
};